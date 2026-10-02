<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Recommendation;
use App\Models\RecommendationInvite;
use App\Models\Survey;
use App\Services\RecommendationScorer;
use App\Support\LegacyCodes;
use App\Support\MediaStore;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyCatalogController extends Controller
{
    public function categories()
    {
        $categories = Category::query()->where('IsDeleted', false)->orderBy('CategoryName')->get();
        $images = $this->images($categories->pluck('CategoryImageId')->merge($categories->pluck('CategoryHeaderId')));
        $rows = $categories->map(function (Category $category) use ($images) {
            $image = $images->get($category->CategoryImageId);
            $header = $images->get($category->CategoryHeaderId);

            return [
                'id' => $category->getKey(),
                'categoryName' => $category->CategoryName,
                'image' => [
                    'originalUrl' => MediaStore::url($image?->OriginalUrl),
                ],
                'header' => [
                    'originalUrl' => MediaStore::url($header?->OriginalUrl),
                ],
            ];
        })->values();

        return response()->json(['data' => $rows, 'status' => '200']);
    }

    public function recent(int $pageSize, int $pageNumber)
    {
        $query = $this->accepted();
        $total = (clone $query)->count();
        $items = $query->orderByDesc('CreationDate')
            ->forPage(max(1, $pageNumber), max(1, $pageSize))
            ->get();

        return response()->json([
            'data' => [
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
                'totalCount' => $total,
                'items' => $this->cards($items),
            ],
            'status' => '200',
        ]);
    }

    public function search(Request $request)
    {
        $term = '%'.(string) $request->query('query', '').'%';
        $items = $this->accepted()
            ->where(function ($query) use ($term) {
                $query->where('Name', 'like', $term)->orWhere('Description', 'like', $term);
            })
            ->orderBy('Name')
            ->limit(100)
            ->get();

        return response()->json(['data' => $this->cards($items), 'status' => '200']);
    }

    public function listByCategory(string $categoryId)
    {
        $items = $this->accepted()->where('CategoryId', $categoryId)->orderBy('Name')->get();

        return response()->json(['data' => $this->cards($items), 'status' => '200']);
    }

    public function details(string $id)
    {
        $business = $this->accepted()->where('businessprofile.Id', $id)->first();
        if (! $business) {
            return response()->json(['data' => null, 'status' => '404', 'message' => 'Listing not found.'], 404);
        }

        $profile = $this->cards(collect([$business]))[0];

        return response()->json([
            'data' => [
                'profile' => $profile,
                'category' => [
                    'id' => $business->category?->getKey(),
                    'categoryName' => $business->category?->CategoryName,
                ],
            ],
            'status' => '200',
        ]);
    }

    public function hours(string $id)
    {
        $rows = DB::table('businesshour')
            ->where('BusinessProfileId', $id)
            ->where('IsDeleted', false)
            ->orderBy('DayOfWeek')
            ->get()
            ->map(fn ($hour) => [
                'dayOfWeek' => (int) $hour->DayOfWeek,
                'openingTime' => $hour->IsClosed ? null : substr((string) $hour->OpeningTime, 0, 5),
                'closingTime' => $hour->IsClosed ? null : substr((string) $hour->ClosingTime, 0, 5),
            ])->values();

        return response()->json(['data' => $rows, 'status' => '200']);
    }

    public function feedback(string $id)
    {
        $rows = DB::table('feedback')
            ->where('BusinessProfileId', $id)
            ->where('IsDeleted', false)
            ->where('ShowToCustomer', true)
            ->orderByDesc('CreationDate')
            ->limit(30)
            ->get()
            ->map(fn ($row) => $this->publicReview($row))
            ->values();

        return response()->json(['data' => $rows, 'status' => '200']);
    }

    public function createLead(Request $request)
    {
        $captured = json_decode((string) $request->input('capturedData', '{}'), true);
        if (! is_array($captured)) {
            $captured = [];
        }
        $email = $captured['email'] ?? $captured['Email'] ?? $request->input('emailAddress') ?? 'visitor@yebokenny.local';
        $name = $captured['name'] ?? $captured['fullName'] ?? $captured['Name'] ?? 'Website visitor';
        $message = $captured['message'] ?? $captured['Message'] ?? '';

        Lead::create([
            'Id' => LegacyCodes::id(),
            'CapturedData' => json_encode(array_merge($captured, ['name' => $name, 'message' => $message])),
            'Email' => $email,
            'LeadStatus' => 0,
            'LeadType' => (int) $request->input('leadType', 2),
            'CapturedDate' => now(),
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
            'RefNo' => 'LD-'.now()->format('ymdHis'),
        ]);

        return response()->json(['data' => ['isSuccess' => true], 'status' => '200']);
    }

    public function surveyByCategory(string $categoryId)
    {
        $category = Category::with('survey')->find($categoryId);
        $survey = $category?->survey;

        return response()->json(['data' => $this->surveyPayload($survey), 'status' => '200']);
    }

    public function surveyByBusiness(string $businessId)
    {
        $business = Business::with('category.survey')->find($businessId);
        $survey = $business?->category?->survey;
        $payload = $this->surveyPayload($survey);
        foreach (['survey1', 'survey2', 'survey3', 'survey4', 'survey5'] as $index => $key) {
            $own = $business?->{'Survey'.($index + 1)};
            if ($own) {
                $payload[$key] = $own;
            }
        }

        return response()->json(['data' => $payload, 'status' => '200']);
    }

    public function createRecommendation(Request $request, RecommendationScorer $scorer)
    {
        $token = (string) $request->input('token', '');
        $invite = RecommendationInvite::with('business')->where('Token', $token)->where('IsDeleted', false)->first();
        $ratings = [];
        foreach (['survey1', 'survey2', 'survey3', 'survey4', 'survey5'] as $key) {
            $value = (int) $request->input($key);
            if ($value >= 1 && $value <= 5) {
                $ratings[] = $value;
            }
        }
        if (! $invite || ! $invite->business || $ratings === []) {
            return response()->json(['data' => ['isSuccess' => false, 'messsage' => 'Invalid token'], 'status' => '200']);
        }

        $score = $scorer->score($ratings);
        $business = $invite->business;
        $email = (string) ($invite->Email ?: $request->input('emailAddress', 'visitor@yebokenny.local'));

        DB::transaction(function () use ($invite, $request, $ratings, $score, $business, $email) {
            $person = \App\Models\AnonymousUser::firstOrCreate(
                ['EmailAddress' => $email],
                [
                    'Id' => LegacyCodes::id(),
                    'IpAddress' => $request->ip() ?: '0.0.0.0',
                    'BrowserName' => $request->userAgent() ?: 'web',
                    'CreationDate' => now(),
                    'IsDeleted' => false,
                    'TenantId' => LegacyCodes::TENANT,
                ]
            );
            $surveyKey = LegacyCodes::id();
            DB::table('surveydata')->insert([
                'Id' => $surveyKey,
                'Data' => (string) $request->input('surveyData', json_encode(['ratings' => $ratings, 'score' => $score])),
                'CreationDate' => now(),
                'IsDeleted' => false,
                'TenantId' => LegacyCodes::TENANT,
            ]);
            $recommendation = Recommendation::create([
                'Id' => LegacyCodes::id(),
                'IsRecommended' => true,
                'SurveyDataId' => $surveyKey,
                'BusinessProfileId' => $business->getKey(),
                'IPAddress' => $request->ip() ?: '0.0.0.0',
                'FingerPrint' => (string) $request->input('fingerPrint', 'web'),
                'Platform' => (string) $request->input('platform', 'web'),
                'Latitude' => 0,
                'Longitude' => 0,
                'CreationDate' => now(),
                'IsDeleted' => false,
                'IsComputed' => true,
                'AnonymousUserId' => $person->getKey(),
                'IsDuplicate' => false,
                'TenantId' => LegacyCodes::TENANT,
            ]);
            DB::table('recommendationscorehistory')->insert([
                'Id' => LegacyCodes::id(),
                'ScoreGiven' => $score,
                'MaxScoreAchievable' => count($ratings) * 10,
                'ScoreFactorUsed' => 10,
                'RawScoresIntepreted' => '{'.collect($ratings)->values()->map(fn ($rating, $index) => 'question'.($index + 1).'='.((int) $rating * 2))->implode(',').'}',
                'RecommendationId' => $recommendation->getKey(),
                'BusinessProfileId' => $business->getKey(),
                'DateComputed' => now(),
                'CreationDate' => now(),
                'IsDeleted' => false,
                'TenantId' => LegacyCodes::TENANT,
            ]);
            $business->YeboKennyScore = (float) $business->YeboKennyScore + $score;
            $business->RecommendationCount = (int) $business->RecommendationCount + 1;
            $business->save();
        });

        return response()->json(['data' => ['isSuccess' => true], 'status' => '200']);
    }

    public function createFeedback(Request $request)
    {
        $business = Business::where('Id', $request->input('businessProfileId'))->where('IsDeleted', false)->first();
        $value = trim((string) $request->input('feedbackValue', ''));
        if (! $business || $value === '') {
            return response()->json(['data' => ['isSuccess' => false], 'message' => 'A review needs a listing and some text.', 'status' => '422'], 422);
        }

        DB::table('feedback')->insert([
            'Id' => LegacyCodes::id(),
            'FeedbackValue' => $value,
            'BusinessProfileId' => $business->getKey(),
            'HasBeenViewed' => false,
            'ShowToCustomer' => true,
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
            'FeedbackWriter' => (string) $request->input('feedbackWriter', 'Anonymous'),
            'FeedbackRating' => (float) $request->input('feedbackRating', 0),
            'FeedbackTitle' => $request->input('feedbackTitle'),
            'FeedbackEmail' => $request->input('feedbackEmail'),
            'FeedbackPhoneNumber' => $request->input('feedbackPhoneNumber'),
            'FeedbackImage1' => $request->input('feedbackImage1'),
            'FeedbackImage2' => $request->input('feedbackImage2'),
        ]);
        $average = DB::table('feedback')
            ->where('BusinessProfileId', $business->getKey())
            ->where('IsDeleted', false)
            ->avg('FeedbackRating');
        $business->Rating = round((float) $average, 2);
        $business->save();

        return response()->json(['data' => ['isSuccess' => true], 'status' => '200']);
    }

    private function publicReview(object $row): array
    {
        $writer = $row->FeedbackWriter ?: 'Anonymous';

        return [
            'feedbackId' => $row->Id,
            'feedbackName' => $writer,
            'feedbackWriter' => $writer,
            'title' => $row->FeedbackTitle ?? '',
            'feedbackRating' => (float) $row->FeedbackRating,
            'feedbackValue' => $row->FeedbackValue,
            'feedbackImage1' => ($first = MediaStore::url($row->FeedbackImage1)) !== '' ? $first : null,
            'feedbackImage2' => ($second = MediaStore::url($row->FeedbackImage2)) !== '' ? $second : null,
        ];
    }

    private function accepted()
    {
        return Business::with(['category', 'owner'])
            ->where('businessprofile.IsDeleted', false)
            ->where('ReviewStatus', LegacyCodes::REVIEW_ACCEPTED);
    }

    private function cards($businesses): array
    {
        $businesses = collect($businesses);
        $images = $this->images($businesses->pluck('BusinessProfileImageId')->merge($businesses->pluck('BusinessHeaderImageId')));

        return $businesses->map(function (Business $business) use ($images) {
            $image = $images->get($business->BusinessProfileImageId);
            $header = $images->get($business->BusinessHeaderImageId);

            return [
                'id' => $business->getKey(),
                'name' => $business->Name,
                'description' => RichText::clean($business->Description),
                'categoryId' => $business->CategoryId,
                'categoryName' => $business->category?->CategoryName,
                'phoneNumber' => $business->PhoneNumber,
                'emailAddress' => $business->EmailAddress,
                'website' => $business->Website,
                'physicalAddress' => $business->PhysicalAddress,
                'recommendationCount' => (int) $business->RecommendationCount,
                'userCountry' => $business->owner?->UserCountry ?: 'South Africa',
                'yeboKennyScore' => (float) $business->YeboKennyScore,
                'rating' => (float) $business->Rating,
                'profileImage' => ($profileUrl = MediaStore::url($image?->OriginalUrl)) !== '' ? [
                    'originalUrl' => $profileUrl,
                ] : null,
                'headerImage' => ($headerUrl = MediaStore::url($header?->OriginalUrl)) !== '' ? [
                    'originalUrl' => $headerUrl,
                ] : null,
            ];
        })->values()->all();
    }

    private function images($ids)
    {
        $keys = collect($ids)->filter()->unique()->values();
        if ($keys->isEmpty()) {
            return collect();
        }

        return DB::table('image')->whereIn('Id', $keys->all())->get()->keyBy('Id');
    }

    private function mediaUrl(?string $path): string
    {
        return MediaStore::url($path);
    }

    private function surveyPayload(?Survey $survey): array
    {
        if (! $survey) {
            return ['json' => '{}', 'survey1' => null, 'survey2' => null, 'survey3' => null, 'survey4' => null, 'survey5' => null];
        }

        return [
            'id' => $survey->getKey(),
            'title' => $survey->Title,
            'json' => (string) ($survey->Json ?? '{}'),
            'survey1' => $survey->Survey1,
            'survey2' => $survey->Survey2,
            'survey3' => $survey->Survey3,
            'survey4' => $survey->Survey4,
            'survey5' => $survey->Survey5,
        ];
    }
}
