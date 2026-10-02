<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnonymousUser;
use App\Models\Recommendation;
use App\Models\RecommendationInvite;
use App\Models\User;
use App\Services\RecommendationScorer;
use App\Support\BrevoMail;
use App\Support\LegacyCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecommendationController extends Controller
{
    public function show(string $token)
    {
        $invite = RecommendationInvite::with('business.category.survey')->where('Token', $token)->where('IsDeleted', false)->first();
        if (! $invite || ! $invite->business) {
            return response()->json(['message' => 'This recommendation link is not valid.'], 404);
        }
        if ($this->alreadySent($invite)) {
            return response()->json(['message' => 'This recommendation has already been sent.'], 422);
        }

        return response()->json([
            'data' => [
                'email' => $invite->Email,
                'business' => [
                    'id' => $invite->business->getKey(),
                    'name' => $invite->business->Name,
                    'category' => $invite->business->category?->CategoryName,
                ],
                'questions' => $invite->business->questions(),
            ],
        ]);
    }

    public function store(Request $request, string $token, RecommendationScorer $scorer)
    {
        $invite = RecommendationInvite::with('business')->where('Token', $token)->where('IsDeleted', false)->first();
        if (! $invite || ! $invite->business) {
            return response()->json(['message' => 'This recommendation link is not valid.'], 404);
        }
        if ($this->alreadySent($invite)) {
            $this->notifyDuplicate($invite);

            return response()->json(['message' => 'This email has already recommended this business.'], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'ratings' => ['required', 'array', 'min:1', 'max:5'],
            'ratings.*' => ['integer', 'between:1,5'],
        ]);

        $score = $scorer->score($data['ratings']);
        $business = $invite->business;

        DB::transaction(function () use ($invite, $data, $score, $business) {
            $person = AnonymousUser::firstOrCreate(
                ['EmailAddress' => $invite->Email],
                [
                    'Id' => LegacyCodes::id(),
                    'IpAddress' => request()->ip() ?: '0.0.0.0',
                    'BrowserName' => request()->userAgent() ?: 'web',
                    'CreationDate' => now(),
                    'IsDeleted' => false,
                    'TenantId' => LegacyCodes::TENANT,
                ]
            );
            $surveyKey = LegacyCodes::id();
            DB::table('surveydata')->insert([
                'Id' => $surveyKey,
                'Data' => json_encode(['name' => $data['name'], 'ratings' => $data['ratings'], 'score' => $score]),
                'CreationDate' => now(),
                'IsDeleted' => false,
                'TenantId' => LegacyCodes::TENANT,
            ]);
            $recommendation = Recommendation::create([
                'Id' => LegacyCodes::id(),
                'IsRecommended' => true,
                'SurveyDataId' => $surveyKey,
                'BusinessProfileId' => $business->getKey(),
                'IPAddress' => request()->ip() ?: '0.0.0.0',
                'FingerPrint' => 'portal',
                'Platform' => 'web',
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
                'MaxScoreAchievable' => count($data['ratings']) * 10,
                'ScoreFactorUsed' => 10,
                'RawScoresIntepreted' => '{'.collect($data['ratings'])->values()->map(fn ($rating, $index) => 'question'.($index + 1).'='.((int) $rating * 2))->implode(',').'}',
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

        return response()->json([
            'data' => [
                'score' => $score,
                'business' => $business->Name,
            ],
        ]);
    }

    private function notifyDuplicate(RecommendationInvite $invite): void
    {
        $business = $invite->business;
        $owner = $business ? User::find($business->ApplicationUserId) : null;
        $name = $business?->Name ?: 'this business';
        if ($owner && $owner->Email) {
            BrevoMail::send(
                $owner->Email,
                $owner->name,
                'Duplicate recommendation',
                BrevoMail::html('Duplicate recommendation', "{$invite->Email} tried to recommend {$name} again. The extra recommendation was not counted.")
            );
        }
        if ($invite->Email) {
            BrevoMail::send(
                $invite->Email,
                $invite->Email,
                'You already recommended this business',
                BrevoMail::html('Already sent', "You have already recommended {$name}. A second recommendation was not added.")
            );
        }
    }

    private function alreadySent(RecommendationInvite $invite): bool
    {
        return Recommendation::where('BusinessProfileId', $invite->BusinessProfileId)
            ->where('IsDeleted', false)
            ->whereHas('anonymousUser', fn ($q) => $q->where('EmailAddress', $invite->Email))
            ->exists();
    }
}
