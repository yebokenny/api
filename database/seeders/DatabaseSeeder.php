<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Models\Business;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\RecommendationInvite;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $experience = Plan::create([
            'name' => 'Experience',
            'slug' => 'experience',
            'description' => 'A free listing so a business can be found and recommended.',
            'price_zar' => 0,
            'price_ngn' => 0,
            'interval' => 'once',
            'is_free' => true,
            'is_enabled' => true,
            'features' => [],
        ]);

        Plan::create([
            'name' => 'Silver',
            'slug' => 'silver',
            'description' => 'Hours, gallery, and the recommendation breakdown.',
            'price_zar' => 85,
            'price_ngn' => 7000,
            'interval' => 'month',
            'is_free' => false,
            'is_enabled' => true,
            'features' => ['VIEW_RECOMMENDATION_DETAILS', 'ADD_BUSINESS_GALLERY', 'ADD_BUSINESS_HOURS'],
        ]);

        $gold = Plan::create([
            'name' => 'Gold',
            'slug' => 'gold',
            'description' => 'Products and services, each with its own recommendations.',
            'price_zar' => 210,
            'price_ngn' => 18000,
            'interval' => 'month',
            'is_free' => false,
            'is_enabled' => true,
            'features' => ['VIEW_RECOMMENDATION_DETAILS', 'ADD_BUSINESS_GALLERY', 'ADD_BUSINESS_HOURS', 'ADD_BUSINESS_ITEM'],
        ]);

        Plan::create([
            'name' => 'Platinum',
            'slug' => 'platinum',
            'description' => 'More than one listing, plus every Gold feature.',
            'price_zar' => 720,
            'price_ngn' => 60000,
            'interval' => 'month',
            'is_free' => false,
            'is_enabled' => true,
            'features' => ['VIEW_RECOMMENDATION_DETAILS', 'ADD_BUSINESS_GALLERY', 'ADD_BUSINESS_HOURS', 'ADD_BUSINESS_ITEM', 'ADD_MULTIPLE_BUSINESSES'],
        ]);

        $questions = [
            'Food and beverages' => [
                'How would you rate the quality of the food or drink?',
                'How welcome did the team make you feel?',
                'How fairly priced was the experience?',
            ],
            'Health' => [
                'How clearly was the care explained?',
                'How comfortable were you during the visit?',
                'How likely are you to return to this practice?',
            ],
            'Beauty' => [
                'How happy are you with the result?',
                'How professional was the service?',
                'How clean and calm was the space?',
            ],
            'Technology' => [
                'How well did the work solve the problem?',
                'How clear was communication along the way?',
                'How would you rate the value for the outcome?',
            ],
        ];

        $categories = collect($questions)->map(function ($items, $name) {
            return Category::create([
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($name),
                'default_questions' => $items,
            ]);
        });

        $ada = $this->user('Ada', 'Okeke', 'ada@yebokenny.com', 'South Africa', 'owner', $gold->id, 'active');
        $chidi = $this->user('Chidi', 'Adebayo', 'chidi@yebokenny.com', 'Nigeria', 'owner', $experience->id, 'free');
        $admin = $this->user('Yebo', 'Admin', 'admin@yebokenny.com', 'South Africa', 'admin', $experience->id, 'free');
        $this->user('Lerato', 'Mokoena', 'crm@yebokenny.com', 'South Africa', 'crm', $experience->id, 'free');

        $grove = Business::create([
            'user_id' => $ada->id,
            'category_id' => $categories['Food and beverages']->id,
            'name' => 'Grove & Grain',
            'slug' => 'grove-and-grain',
            'description' => 'A Cape Town bakery known for slow-fermented bread and a calm weekend service.',
            'phone' => '+27 21 555 0142',
            'email' => 'hello@groveandgrain.example',
            'address' => '18 Bree Street, Cape Town',
            'website' => 'https://groveandgrain.example',
            'country' => 'South Africa',
            'yebo_score' => 30,
            'recommendation_count' => 1,
            'review_status' => 'accepted',
            'amenities' => ['Outdoor seating', 'Card payments', 'Wheelchair access'],
            'hours' => $this->hours(),
            'survey_questions' => [],
        ]);

        Business::create([
            'user_id' => $chidi->id,
            'category_id' => $categories['Technology']->id,
            'name' => 'Lagos Signal Studio',
            'slug' => 'lagos-signal-studio',
            'description' => 'A product studio in Lagos that designs and ships software for growing businesses.',
            'phone' => '+234 1 700 4410',
            'email' => 'hello@lagossignal.example',
            'address' => '12 Admiralty Way, Lekki',
            'country' => 'Nigeria',
            'review_status' => 'accepted',
            'amenities' => ['Remote friendly', 'Workshops'],
            'hours' => $this->hours(),
            'survey_questions' => [
                'How clearly did the team explain the work?',
                'How useful was the result for your business?',
            ],
        ]);

        RecommendationInvite::create([
            'business_id' => $grove->id,
            'email' => 'naledi@example.com',
            'token' => 'completed-demo-token',
            'answers' => [5, 5, 5],
            'recommender_name' => 'Naledi Khumalo',
            'score' => 30,
            'is_computed' => true,
            'completed_at' => now()->subDays(2),
        ]);

        Blog::create([
            'user_id' => $admin->id,
            'title' => 'Why positive recommendations compound',
            'category' => 'Growth',
            'details' => 'A recommendation is more useful when it is specific. Yebokenny asks people who have actually used a business to rate the parts of the experience that matter in that category. Those answers add to a score the next customer can see.',
            'status' => 'Active',
            'likes' => 12,
            'views' => 140,
        ]);

        Lead::create([
            'name' => 'Thandiwe Ndlovu',
            'email' => 'thandiwe@example.com',
            'phone' => '+27 82 000 1001',
            'type' => 'contact',
            'status' => 'open',
            'message' => 'We run three salons in Durban and want to understand Platinum.',
        ]);
        Lead::create([
            'name' => 'Ibrahim Musa',
            'email' => 'ibrahim@example.com',
            'type' => 'partner',
            'status' => 'responded',
            'message' => 'Interested in introducing Yebokenny to hospitality groups in Abuja.',
            'notes' => 'Sent the partner overview on Monday.',
        ]);
    }

    private function user(string $first, string $last, string $email, string $country, string $role, int $planId, string $status): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => $last,
            'name' => $first.' '.$last,
            'email' => $email,
            'password' => 'YeboKenny1',
            'country' => $country,
            'role' => $role,
            'plan_id' => $planId,
            'subscription_status' => $status,
        ]);
    }

    private function hours(): array
    {
        return collect(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])
            ->map(fn ($day) => [
                'day' => $day,
                'open' => $day === 'Sunday' ? null : '08:00',
                'close' => $day === 'Sunday' ? null : '17:00',
                'closed' => $day === 'Sunday',
            ])->all();
    }
}
