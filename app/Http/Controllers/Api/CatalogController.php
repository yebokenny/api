<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Survey;
use App\Support\LegacyCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function categories()
    {
        $categories = Category::query()->where('IsDeleted', false)->with(['survey', 'icon', 'header'])
            ->withCount(['businesses' => fn ($query) => $query->where('IsDeleted', false)])
            ->orderBy('CategoryName')
            ->get();

        return response()->json([
            'data' => $categories->map->toPublic()->values(),
        ]);
    }

    public function plans(Request $request)
    {
        $query = Plan::with('featureRows')->orderBy('Price');
        if (! $request->boolean('includeDisabled')) {
            $query->where('IsEnabled', true);
        }

        return response()->json([
            'data' => $query->get()->map->toPublic()->values(),
        ]);
    }

    public function businesses(Request $request)
    {
        $query = Business::with(['category.survey', 'category.icon', 'category.header', 'owner.plan', 'owner.author.image', 'amenities', 'hours', 'products', 'profileImage', 'headerImage', 'gallery'])
            ->where('IsDeleted', false)
            ->where('ReviewStatus', LegacyCodes::REVIEW_ACCEPTED);

        if ($request->filled('country')) {
            $country = (string) $request->string('country');
            $query->whereHas('owner', fn ($q) => $q->where('UserCountry', $country));
        }
        if ($request->filled('category')) {
            $slug = (string) $request->string('category');
            $query->whereHas('category', fn ($q) => $q->where('CategoryName', 'like', str_replace('-', '%', $slug)));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->string('q').'%';
            $query->where(function ($q) use ($term) {
                $q->where('Name', 'like', $term)->orWhere('Description', 'like', $term);
            });
        }

        $items = $query->get()->sortBy([
            fn ($business) => $business->owner?->plan?->IsFree ? 1 : 0,
            fn ($business) => $business->Name,
        ])->values();

        return response()->json([
            'data' => $items->map(fn (Business $business) => $business->toPublic())->values(),
        ]);
    }

    public function business(Request $request, Business $business)
    {
        if ($business->IsDeleted) {
            return response()->json(['message' => 'This listing was not found.'], 404);
        }
        $business->load(['category.survey', 'category.icon', 'category.header', 'owner.author.image', 'amenities', 'hours', 'products', 'profileImage', 'headerImage', 'gallery']);
        DB::table('pagetrack')->insert([
            'Id' => LegacyCodes::id(),
            'BusinessProfileId' => $business->getKey(),
            'Ip' => $request->ip() ?: '0.0.0.0',
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
        ]);

        return response()->json(['data' => $business->toPublic()]);
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'string', 'max:500'],
            'isEnabled' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $plan->Name = $data['name'];
        }
        if (isset($data['description'])) {
            $plan->Description = $data['description'];
        }
        if (isset($data['isEnabled'])) {
            $plan->IsEnabled = $data['isEnabled'];
        }
        $plan->save();
        $plan->load('featureRows');

        return response()->json(['data' => $plan->toPublic()]);
    }

    public function storeCategory(Request $request)
    {
        $data = $this->categoryInput($request);
        $this->guardUniqueName($data['name']);
        $survey = $this->makeSurvey($data['name'], $data['questions']);
        $category = new Category;
        $category->Id = LegacyCodes::id();
        $category->CategoryName = $data['name'];
        $category->SurveyId = $survey->getKey();
        $category->CreationDate = now();
        $category->IsDeleted = false;
        $category->TenantId = LegacyCodes::TENANT;
        $category->save();
        $category->load(['survey', 'icon', 'header']);
        $category->businesses_count = 0;

        return response()->json(['data' => $category->toPublic()], 201);
    }

    public function updateCategory(Request $request, Category $category)
    {
        if ($category->IsDeleted) {
            return response()->json(['message' => 'This category was not found.'], 404);
        }
        $data = $this->categoryInput($request);
        $this->guardUniqueName($data['name'], $category->getKey());
        $survey = $this->exclusiveSurvey($category);
        $this->writeQuestions($survey, $data['name'], $data['questions']);
        $category->CategoryName = $data['name'];
        $category->SurveyId = $survey->getKey();
        $category->ModificationDate = now();
        $category->save();
        $category->load(['survey', 'icon', 'header']);
        $category->loadCount(['businesses' => fn ($query) => $query->where('IsDeleted', false)]);

        return response()->json(['data' => $category->toPublic()]);
    }

    public function destroyCategory(Category $category)
    {
        if ($category->IsDeleted) {
            return response()->json(['message' => 'This category was not found.'], 404);
        }
        if ($category->businesses()->where('IsDeleted', false)->exists()) {
            return response()->json(['message' => 'Move the listings in this category before deleting it.'], 422);
        }

        $category->IsDeleted = true;
        $category->DeletionDate = now();
        $category->ModificationDate = now();
        $category->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function categoryInput(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'questions' => ['required', 'array', 'size:5'],
            'questions.*' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'questions.*.required' => 'Enter all five survey questions.',
            'questions.*.min' => 'Each question needs at least 10 characters.',
            'questions.*.max' => 'Each question needs to be 500 characters or fewer.',
        ]);
        $data['name'] = trim($data['name']);
        $data['questions'] = array_map(fn ($question) => trim((string) $question), $data['questions']);

        return $data;
    }

    private function guardUniqueName(string $name, ?string $ignoreId = null): void
    {
        $query = Category::query()->where('IsDeleted', false)->where('CategoryName', $name);
        if ($ignoreId) {
            $query->where('Id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'A category with this title already exists.',
            ]);
        }
    }

    private function exclusiveSurvey(Category $category): Survey
    {
        $survey = $category->survey;
        if (! $survey) {
            return $this->makeSurvey($category->CategoryName, ['', '', '', '', '']);
        }
        $shared = Category::query()
            ->where('IsDeleted', false)
            ->where('SurveyId', $survey->getKey())
            ->where('Id', '!=', $category->getKey())
            ->exists();
        if (! $shared) {
            return $survey;
        }

        $copy = $survey->replicate();
        $copy->Id = LegacyCodes::id();
        $copy->CreationDate = now();
        $copy->ModificationDate = null;
        $copy->DeletionDate = null;
        $copy->IsDeleted = false;
        $copy->TenantId = LegacyCodes::TENANT;
        $copy->save();

        return $copy;
    }

    private function makeSurvey(string $name, array $questions): Survey
    {
        $survey = new Survey;
        $survey->Id = LegacyCodes::id();
        $survey->CreationDate = now();
        $survey->IsDeleted = false;
        $survey->TenantId = LegacyCodes::TENANT;
        $survey->Json = json_encode($this->blankSurvey($name, $questions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->writeQuestions($survey, $name, $questions);

        return $survey;
    }

    private function writeQuestions(Survey $survey, string $name, array $questions): void
    {
        $data = json_decode((string) $survey->Json, true);
        if (! is_array($data)) {
            $data = $this->blankSurvey($name, $questions);
        }
        $data['title'] = $name.' Survey';
        foreach ($data['pages'] ?? [] as $pageIndex => $page) {
            foreach ($page['elements'] ?? [] as $elementIndex => $element) {
                $elementName = (string) ($element['name'] ?? '');
                if (! preg_match('/^question([1-5])$/', $elementName, $match)) {
                    continue;
                }
                $data['pages'][$pageIndex]['elements'][$elementIndex]['title'] = $questions[(int) $match[1] - 1];
            }
        }
        foreach ([1, 2, 3, 4, 5] as $number) {
            $survey->{'Survey'.$number} = $questions[$number - 1];
        }
        $survey->Title = $name.' Survey';
        $survey->Json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $survey->ModificationDate = now();
        $survey->save();
    }

    private function blankSurvey(string $name, array $questions): array
    {
        $pages = [];
        foreach ($questions as $index => $question) {
            $number = $index + 1;
            $pages[] = [
                'name' => 'page'.$number,
                'elements' => [[
                    'type' => 'rating',
                    'name' => 'question'.$number,
                    'title' => $question,
                    'description' => 'Rate out of 5. 1 being the lowest possible score and 5 being the highest.',
                    'isRequired' => true,
                    'rateMin' => 1,
                    'rateMax' => 5,
                ]],
            ];
        }
        $pages[] = [
            'name' => 'page6',
            'title' => 'Are you human?',
            'description' => 'We need to check that you are not a robot.',
            'elements' => [[
                'type' => 'signaturepad',
                'name' => 'question6',
                'title' => 'Jot down your initials, name or something to show you are indeed human.',
                'description' => 'DO NOT PUT YOUR ACTUAL SIGNATURE.',
                'isRequired' => true,
            ]],
        ];

        return [
            'title' => $name.' Survey',
            'description' => 'A survey about the service you are recommending',
            'pages' => $pages,
        ];
    }
}
