<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessAmenity;
use App\Models\BusinessHour;
use App\Models\BusinessItem;
use App\Models\Category;
use App\Models\Recommendation;
use App\Models\RecommendationInvite;
use App\Support\LegacyCodes;
use App\Support\RichText;
use App\Support\ScoreSeries;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessController extends Controller
{
    public function mine(Request $request)
    {
        $businesses = Business::with(['category.survey', 'category.icon', 'category.header', 'amenities', 'hours', 'products', 'profileImage', 'headerImage', 'gallery'])
            ->where('ApplicationUserId', $request->user()->getKey())
            ->where('IsDeleted', false)
            ->orderByDesc('CreationDate')
            ->get();

        return response()->json([
            'data' => $businesses->map(fn (Business $business) => $business->toPublic())->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $existing = Business::where('ApplicationUserId', $user->getKey())->where('IsDeleted', false)->count();
        if ($existing > 0 && ! $user->hasFeature('ADD_MULTIPLE_BUSINESSES')) {
            return response()->json([
                'message' => 'Your plan includes one listing. Upgrade to add another.',
            ], 403);
        }

        $data = $this->validated($request);
        if (! empty($data['amenities']) && ! $user->hasFeature('ADD_BUSINESS_AMENITIES')) {
            return response()->json([
                'message' => 'Amenities are included with Silver, Gold, and Platinum.',
            ], 403);
        }
        $category = Category::findOrFail($data['categoryId']);
        $questions = array_values(array_filter($data['surveyQuestions'] ?? []));

        $business = Business::create([
            'Id' => LegacyCodes::id(),
            'ApplicationUserId' => $user->getKey(),
            'CategoryId' => $category->getKey(),
            'Name' => $data['name'],
            'Description' => RichText::clean($data['description']),
            'PhoneNumber' => $data['phone'] ?? '',
            'EmailAddress' => $data['email'] ?? $user->Email,
            'PhysicalAddress' => $data['address'] ?? null,
            'Website' => $data['website'] ?? null,
            'ReviewStatus' => LegacyCodes::REVIEW_ACCEPTED,
            'RecommendationCount' => 0,
            'YeboKennyScore' => 0,
            'Survey1' => $questions[0] ?? null,
            'Survey2' => $questions[1] ?? null,
            'Survey3' => $questions[2] ?? null,
            'Survey4' => $questions[3] ?? null,
            'Survey5' => $questions[4] ?? null,
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
        ]);

        foreach ($data['amenities'] ?? [] as $amenity) {
            BusinessAmenity::create([
                'Id' => LegacyCodes::id(),
                'Amenity' => $amenity,
                'BusinessProfileId' => $business->getKey(),
                'CreationDate' => now(),
                'IsDeleted' => false,
                'TenantId' => LegacyCodes::TENANT,
            ]);
        }

        $business->load(['category.survey', 'amenities', 'hours']);

        return response()->json(['data' => $business->toPublic()], 201);
    }

    public function update(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        $data = $this->validated($request, false);
        if (isset($data['categoryId'])) {
            $business->CategoryId = $data['categoryId'];
        }
        $business->fill([
            'Name' => $data['name'] ?? $business->Name,
            'Description' => isset($data['description']) ? RichText::clean($data['description']) : $business->Description,
            'PhoneNumber' => array_key_exists('phone', $data) ? (string) ($data['phone'] ?? '') : $business->PhoneNumber,
            'EmailAddress' => array_key_exists('email', $data) ? (string) ($data['email'] ?? '') : $business->EmailAddress,
            'PhysicalAddress' => array_key_exists('address', $data) ? ($data['address'] ?: null) : $business->PhysicalAddress,
            'Website' => array_key_exists('website', $data) ? ($data['website'] ?: null) : $business->Website,
        ]);
        if (isset($data['surveyQuestions'])) {
            $questions = array_values($data['surveyQuestions']);
            $business->Survey1 = $questions[0] ?? null;
            $business->Survey2 = $questions[1] ?? null;
            $business->Survey3 = $questions[2] ?? null;
            $business->Survey4 = $questions[3] ?? null;
            $business->Survey5 = $questions[4] ?? null;
        }
        $business->ModificationDate = now();
        $business->save();
        $business->load(['category.survey', 'amenities', 'hours', 'owner']);

        return response()->json(['data' => $business->toPublic()]);
    }

    public function summary(Request $request)
    {
        $businesses = Business::with(['category.icon', 'category.header', 'amenities', 'hours', 'products', 'owner.author.image', 'profileImage', 'headerImage', 'gallery'])
            ->where('ApplicationUserId', $request->user()->getKey())
            ->where('IsDeleted', false)
            ->get();
        $ids = $businesses->pluck('Id');
        $history = $ids->isEmpty() ? collect() : DB::table('recommendationscorehistory')
            ->whereIn('BusinessProfileId', $ids)
            ->where('IsDeleted', false)
            ->where('DateComputed', '>=', now()->subYear()->startOfMonth())
            ->get(['ScoreGiven', 'DateComputed']);
        $recent = $ids->isEmpty() ? collect() : Recommendation::with(['business', 'anonymousUser'])
            ->whereIn('BusinessProfileId', $ids)
            ->where('IsComputed', true)
            ->orderByDesc('CreationDate')
            ->limit(6)
            ->get();

        return response()->json([
            'data' => [
                'listingCount' => $businesses->count(),
                'activeListings' => $businesses->where('ReviewStatus', LegacyCodes::REVIEW_ACCEPTED)->count(),
                'listingViews' => $ids->isEmpty() ? 0 : DB::table('pagetrack')->whereIn('BusinessProfileId', $ids)->where('IsDeleted', false)->count(),
                'yeboScore' => (float) $businesses->sum('YeboKennyScore'),
                'scoreSeries' => ScoreSeries::fromRows($history),
                'recommendationCount' => (int) $businesses->sum('RecommendationCount'),
                'businesses' => $businesses->map(fn (Business $business) => $business->toPublic())->values(),
                'recent' => $recent->map(fn (Recommendation $row) => [
                    'id' => $row->getKey(),
                    'email' => $row->anonymousUser?->EmailAddress,
                    'name' => $row->anonymousUser?->EmailAddress,
                    'score' => null,
                    'business' => $row->business?->Name,
                    'completedAt' => $row->CreationDate,
                ])->values(),
            ],
        ]);
    }

    public function allRecommendations(Request $request)
    {
        $owned = Business::with('category.survey')
            ->where('ApplicationUserId', $request->user()->getKey())
            ->where('IsDeleted', false)
            ->orderBy('Name')
            ->get();
        $businesses = $owned;
        if ($request->filled('business')) {
            $businesses = $owned->filter(fn (Business $business) => $business->getKey() === (string) $request->query('business'))->values();
            if ($businesses->isEmpty()) {
                return response()->json(['message' => 'That listing was not found.'], 404);
            }
        }
        $ids = $businesses->map(fn (Business $business) => $business->getKey());
        $completed = $ids->isEmpty() ? collect() : Recommendation::with(['business', 'anonymousUser'])
            ->whereIn('BusinessProfileId', $ids)
            ->where('IsDeleted', false)
            ->orderByDesc('CreationDate')
            ->get();
        $surveyRows = $completed->isEmpty() ? collect() : DB::table('surveydata')->whereIn('Id', $completed->pluck('SurveyDataId')->filter())->get()->keyBy('Id');
        $scores = $completed->isEmpty() ? collect() : DB::table('recommendationscorehistory')->whereIn('RecommendationId', $completed->map->getKey())->where('IsDeleted', false)->get()->keyBy('RecommendationId');
        $invites = $ids->isEmpty() ? collect() : RecommendationInvite::with('business')
            ->whereIn('BusinessProfileId', $ids)
            ->where('IsDeleted', false)
            ->orderByDesc('CreationDate')
            ->get();
        $answered = $completed->map(fn (Recommendation $row) => strtolower((string) $row->anonymousUser?->EmailAddress).'|'.$row->BusinessProfileId);

        return response()->json([
            'data' => [
                'listings' => $owned->map(fn (Business $business) => [
                    'id' => $business->getKey(),
                    'name' => $business->Name,
                ])->values(),
                'pending' => $invites->filter(fn (RecommendationInvite $invite) => ! $answered->contains(strtolower((string) $invite->Email).'|'.$invite->BusinessProfileId))
                    ->map(fn (RecommendationInvite $invite) => [
                        'id' => $invite->getKey(),
                        'businessId' => $invite->BusinessProfileId,
                        'business' => $invite->business?->Name,
                        'email' => $invite->Email,
                        'token' => $invite->Token,
                        'createdAt' => $invite->CreationDate,
                    ])->values(),
                'recommendations' => $completed->map(function (Recommendation $row) use ($surveyRows, $scores, $businesses) {
                    $business = $businesses->first(fn (Business $item) => $item->getKey() === $row->BusinessProfileId) ?? $row->business;
                    $parsed = $this->recommendationAnswers($surveyRows->get($row->SurveyDataId)?->Data, $business);
                    $history = $scores->get($row->getKey());

                    return [
                        'id' => $row->getKey(),
                        'businessId' => $row->BusinessProfileId,
                        'business' => $business?->Name,
                        'name' => $parsed['name'],
                        'email' => $row->anonymousUser?->EmailAddress,
                        'score' => $history ? (float) $history->ScoreGiven : $parsed['score'],
                        'answers' => $parsed['answers'],
                        'completedAt' => $row->CreationDate,
                    ];
                })->values(),
            ],
        ]);
    }

    public function invite(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $exists = RecommendationInvite::where('BusinessProfileId', $business->getKey())
            ->where('Email', $data['email'])
            ->where('IsDeleted', false)
            ->exists();
        if ($exists) {
            return response()->json([
                'message' => 'This person already has a recommendation link for this business.',
            ], 422);
        }

        $invite = RecommendationInvite::create([
            'Id' => LegacyCodes::id(),
            'BusinessProfileId' => $business->getKey(),
            'Email' => $data['email'],
            'Token' => Str::uuid()->toString(),
            'CreatedAt' => now(),
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
        ]);

        return response()->json([
            'data' => [
                'token' => $invite->Token,
                'email' => $invite->Email,
            ],
        ], 201);
    }

    public function recommendations(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        $rows = $business->invites()->orderByDesc('CreationDate')->get();

        return response()->json([
            'data' => $rows->map(fn (RecommendationInvite $invite) => [
                'id' => $invite->getKey(),
                'email' => $invite->Email,
                'name' => null,
                'score' => null,
                'isComputed' => false,
                'completedAt' => null,
                'token' => $invite->Token,
            ])->values(),
        ]);
    }

    public function storeAmenity(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        if (! $request->user()->hasFeature('ADD_BUSINESS_AMENITIES')) {
            return response()->json(['message' => 'Amenities are included with Silver, Gold, and Platinum.'], 403);
        }
        $data = $request->validate(['amenity' => ['required', 'string', 'max:80']]);
        $exists = $business->amenities()->where('Amenity', $data['amenity'])->exists();
        if ($exists) {
            return response()->json(['message' => 'That amenity is already on this listing.'], 422);
        }
        $row = BusinessAmenity::create([
            'Id' => LegacyCodes::id(),
            'Amenity' => $data['amenity'],
            'BusinessProfileId' => $business->getKey(),
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
        ]);

        return response()->json(['data' => ['id' => $row->getKey(), 'name' => $row->Amenity]], 201);
    }

    public function destroyAmenity(Request $request, Business $business, string $amenity)
    {
        $this->authorizeOwner($request, $business);
        if (! $request->user()->hasFeature('ADD_BUSINESS_AMENITIES')) {
            return response()->json(['message' => 'Amenities are included with Silver, Gold, and Platinum.'], 403);
        }
        $row = $business->amenities()->where('Id', $amenity)->first();
        if (! $row) {
            return response()->json(['message' => 'That amenity was not found.'], 404);
        }
        $row->IsDeleted = true;
        $row->DeletionDate = now();
        $row->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function reviews(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        $rows = DB::table('feedback')
            ->where('BusinessProfileId', $business->getKey())
            ->where('IsDeleted', false)
            ->orderByDesc('CreationDate')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->Id,
                'writer' => $row->FeedbackWriter ?: 'Anonymous',
                'email' => $row->FeedbackEmail,
                'phone' => $row->FeedbackPhoneNumber,
                'title' => $row->FeedbackTitle ?? '',
                'body' => $row->FeedbackValue,
                'rating' => (float) $row->FeedbackRating,
                'image1' => $this->reviewImage($row->FeedbackImage1),
                'image2' => $this->reviewImage($row->FeedbackImage2),
                'shown' => (bool) $row->ShowToCustomer,
                'createdAt' => $row->CreationDate,
            ])->values();

        return response()->json(['data' => $rows]);
    }

    public function updateReview(Request $request, Business $business, string $review)
    {
        $this->authorizeOwner($request, $business);
        $row = DB::table('feedback')->where('Id', $review)->where('BusinessProfileId', $business->getKey())->where('IsDeleted', false)->first();
        if (! $row) {
            return response()->json(['message' => 'That review was not found.'], 404);
        }
        $data = $request->validate(['shown' => ['required', 'boolean']]);
        DB::table('feedback')->where('Id', $review)->update([
            'ShowToCustomer' => $data['shown'],
            'ModificationDate' => now(),
        ]);

        return response()->json(['data' => ['id' => $review, 'shown' => $data['shown']]]);
    }

    public function updateHours(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        if (! $this->canSetHours($request)) {
            return response()->json(['message' => 'Opening hours are included with Silver, Gold, and Platinum.'], 403);
        }
        $data = $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day' => ['required', 'integer', 'between:0,6'],
            'hours.*.closed' => ['required', 'boolean'],
            'hours.*.open' => ['nullable', 'date_format:H:i'],
            'hours.*.close' => ['nullable', 'date_format:H:i'],
        ]);

        foreach ($data['hours'] as $slot) {
            $closed = (bool) $slot['closed'];
            $values = [
                'OpeningTime' => $closed ? null : ($slot['open'] ?? '08:00').':00',
                'ClosingTime' => $closed ? null : ($slot['close'] ?? '17:00').':00',
                'IsClosed' => $closed,
                'ModificationDate' => now(),
            ];
            $existing = $business->hours()->where('DayOfWeek', $slot['day'])->first();
            if ($existing) {
                $existing->fill($values);
                $existing->save();
                continue;
            }
            BusinessHour::create([
                'Id' => LegacyCodes::id(),
                'DayOfWeek' => $slot['day'],
                'BusinessProfileId' => $business->getKey(),
                ...$values,
                'CreationDate' => now(),
                'IsDeleted' => false,
                'TenantId' => LegacyCodes::TENANT,
            ]);
        }
        $business->load('hours');

        return response()->json(['data' => $business->toPublic()['hours']]);
    }

    public function products(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);

        return response()->json([
            'data' => $business->products()->orderBy('Name')->get()->map->toPublic()->values(),
        ]);
    }

    public function storeProduct(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        if (! $request->user()->hasFeature('ADD_BUSINESS_ITEM')) {
            return response()->json(['message' => 'Product listings are included with Silver, Gold, and Platinum.'], 403);
        }
        $data = $this->productData($request);
        $item = BusinessItem::create([
            'Id' => LegacyCodes::id(),
            'BusinessProfileId' => $business->getKey(),
            'BusinessItemType' => 1,
            ...$this->productColumns($data),
            'RecommendationCount' => 0,
            'YeboKennyScore' => 0,
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
        ]);

        return response()->json(['data' => $item->toPublic()], 201);
    }

    public function updateProduct(Request $request, Business $business, string $product)
    {
        $this->authorizeOwner($request, $business);
        if (! $request->user()->hasFeature('ADD_BUSINESS_ITEM')) {
            return response()->json(['message' => 'Product listings are included with Silver, Gold, and Platinum.'], 403);
        }
        $item = $business->products()->where('Id', $product)->first();
        if (! $item) {
            return response()->json(['message' => 'That product was not found.'], 404);
        }
        $item->fill($this->productColumns($this->productData($request)));
        $item->ModificationDate = now();
        $item->save();

        return response()->json(['data' => $item->toPublic()]);
    }

    public function destroyProduct(Request $request, Business $business, string $product)
    {
        $this->authorizeOwner($request, $business);
        if (! $request->user()->hasFeature('ADD_BUSINESS_ITEM')) {
            return response()->json(['message' => 'Product listings are included with Silver, Gold, and Platinum.'], 403);
        }
        $item = $business->products()->where('Id', $product)->first();
        if (! $item) {
            return response()->json(['message' => 'That product was not found.'], 404);
        }
        $item->IsDeleted = true;
        $item->DeletionDate = now();
        $item->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function productData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'description' => ['required', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'url' => ['nullable', 'string', 'max:240'],
            'tags' => ['nullable', 'string', 'max:180'],
        ]);
    }

    private function productColumns(array $data): array
    {
        return [
            'Name' => $data['name'],
            'Description' => $data['description'],
            'Pricing' => $data['price'] ?? null,
            'ExternalUrl' => $data['url'] ?? null,
            'Tags' => $data['tags'] ?? null,
            'SurveyQ1' => 'How would you rate this product?',
            'SurveyQ2' => 'Was it worth the price?',
            'SurveyQ3' => 'Would you recommend it?',
        ];
    }

    private function recommendationAnswers(?string $raw, ?Business $business): array
    {
        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            $decoded = [];
        }
        unset($decoded['question6']);
        $ratings = [];
        $name = is_string($decoded['name'] ?? null) ? $decoded['name'] : null;
        if (isset($decoded['ratings']) && is_array($decoded['ratings'])) {
            $ratings = array_values(array_map('intval', $decoded['ratings']));
        } else {
            for ($index = 1; $index <= 5; $index++) {
                if (isset($decoded['question'.$index]) && is_numeric($decoded['question'.$index])) {
                    $ratings[] = (int) $decoded['question'.$index];
                }
            }
        }
        $questions = $this->questionLabels($business);
        $answers = [];
        foreach ($ratings as $index => $rating) {
            if ($rating < 1 || $rating > 5) {
                continue;
            }
            $answers[] = [
                'question' => $questions[$index] ?? 'Question '.($index + 1),
                'rating' => $rating,
            ];
        }
        $score = isset($decoded['score']) ? (float) $decoded['score'] : (new \App\Services\RecommendationScorer)->score($ratings);

        return ['name' => $name, 'answers' => $answers, 'score' => $score];
    }

    private function questionLabels(?Business $business): array
    {
        $fallback = ['Question 1', 'Question 2', 'Question 3', 'Question 4', 'Question 5'];
        if (! $business) {
            return $fallback;
        }
        $own = [$business->Survey1, $business->Survey2, $business->Survey3, $business->Survey4, $business->Survey5];
        $category = [
            $business->category?->survey?->Survey1,
            $business->category?->survey?->Survey2,
            $business->category?->survey?->Survey3,
            $business->category?->survey?->Survey4,
            $business->category?->survey?->Survey5,
        ];
        $source = collect($own)->contains(fn ($value) => is_string($value) && trim($value) !== '') ? $own : $category;

        return array_map(function ($value, int $index) use ($fallback) {
            return is_string($value) && trim($value) !== '' ? trim($value) : $fallback[$index];
        }, $source, array_keys($source));
    }

    private function reviewImage(?string $value): ?string
    {
        $url = \App\Support\MediaStore::url($value, 'review');

        return $url === '' ? null : $url;
    }

    private function canSetHours(Request $request): bool
    {
        $user = $request->user();

        return $user->hasFeature('ADD_BUSINESS_HOURS') || $user->hasFeature('ADD_BUSINESS_AMENITIES');
    }

    public function destroy(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        if ($business->IsDeleted) {
            return response()->json(['message' => 'This listing was already removed.'], 404);
        }

        $business->IsDeleted = true;
        $business->DeletionDate = now();
        $business->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function authorizeOwner(Request $request, Business $business): void
    {
        if ($business->ApplicationUserId !== $request->user()->getKey() && $request->user()->role !== 'admin') {
            throw new HttpResponseException(
                response()->json(['message' => 'This listing belongs to another account.'], 403)
            );
        }
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:140'],
            'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:60000'],
            'categoryId' => [$creating ? 'required' : 'sometimes', 'string', 'exists:category,Id'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:240'],
            'website' => ['nullable', 'string', 'max:180'],
            'amenities' => ['nullable', 'array', 'max:12'],
            'amenities.*' => ['string', 'max:40'],
            'surveyQuestions' => ['nullable', 'array', 'max:5'],
            'surveyQuestions.*' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
