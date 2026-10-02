<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\BrevoMail;
use App\Support\SiteSettings;
use Illuminate\Console\Command;

class SendScoreReports extends Command
{
    protected $signature = 'yebo:score-reports {--send : Email owners when monthly reports are turned on}';

    protected $description = 'Email each owner a summary of listing scores';

    public function handle(): int
    {
        $settings = SiteSettings::all();
        if (! $this->option('send') || ! $settings['scoreReports'] || ! BrevoMail::configured()) {
            $this->info('Score reports stayed off. Turn them on in admin settings and add a Brevo key before they send.');

            return self::SUCCESS;
        }

        $owners = User::query()
            ->where('AllowEmailNotifications', true)
            ->where('IsLocked', false)
            ->whereHas('businesses', fn ($query) => $query->where('IsDeleted', false)->where('RecommendationCount', '>', 0))
            ->with(['businesses' => fn ($query) => $query->where('IsDeleted', false)])
            ->get();

        $sent = 0;
        foreach ($owners as $owner) {
            $lines = $owner->businesses->map(function ($business) {
                return $business->Name.': '.$business->YeboKennyScore.' from '.$business->RecommendationCount.' recommendations';
            })->implode("\n");
            if ($lines === '') {
                continue;
            }
            $ok = BrevoMail::send(
                $owner->Email,
                $owner->name,
                'Your Yebokenny score this month',
                BrevoMail::html('Your Yebokenny score', "Here is where your listings stand:\n\n".$lines)
            );
            if ($ok) {
                $sent++;
            }
        }

        $this->info('Sent '.$sent.' score reports.');

        return self::SUCCESS;
    }
}
