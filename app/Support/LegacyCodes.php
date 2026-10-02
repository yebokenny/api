<?php

namespace App\Support;

use Illuminate\Support\Str;

class LegacyCodes
{
    public const TENANT = '93a7e18a-aa09-4888-9123-a4240ee18a97';

    public const REVIEW_PENDING = 98374;

    public const REVIEW_ACCEPTED = 203947;

    public const REVIEW_REJECTED = 309732;

    public const USER_INDIVIDUAL = 93800;

    public const USER_BUSINESS = 49245;

    public const USER_ADMIN = 173127;

    public static function id(): string
    {
        return (string) Str::uuid();
    }

    public static function reviewLabel(int $status): string
    {
        return match ($status) {
            self::REVIEW_PENDING => 'pending',
            self::REVIEW_REJECTED => 'rejected',
            default => 'accepted',
        };
    }

    public static function subscriptionLabel(int $status): string
    {
        return match ($status) {
            1 => 'active',
            2 => 'disabled',
            3 => 'non_renewing',
            4 => 'attention',
            default => 'free',
        };
    }
}
