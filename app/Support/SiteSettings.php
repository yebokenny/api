<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SiteSettings
{
    public const KEYS = [
        'maintenance',
        'maintenanceEnds',
        'websiteUrl',
        'adminUrl',
        'googleLogin',
        'scoreReports',
    ];

    public static function all(): array
    {
        self::ensure();
        $stored = DB::table('sitesetting')->pluck('SettingValue', 'SettingKey');
        $defaults = [
            'maintenance' => '0',
            'maintenanceEnds' => '',
            'websiteUrl' => rtrim((string) config('services.paystack.portal_url'), '/'),
            'adminUrl' => 'http://127.0.0.1:3002',
            'googleLogin' => '0',
            'scoreReports' => '0',
        ];

        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = (string) ($stored[$key] ?? $defaults[$key]);
        }
        foreach (['maintenance', 'googleLogin', 'scoreReports'] as $flag) {
            $out[$flag] = $out[$flag] === '1';
        }
        if ($out['googleLogin']) {
            $out['googleClientId'] = (string) config('services.google.client_id');
        }

        return $out;
    }

    public static function replace(array $input): array
    {
        self::ensure();
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (in_array($key, ['maintenance', 'googleLogin', 'scoreReports'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            } else {
                $value = trim((string) $value);
            }
            DB::table('sitesetting')->updateOrInsert(
                ['SettingKey' => $key],
                ['SettingValue' => $value]
            );
        }

        return self::all();
    }

    private static function ensure(): void
    {
        if (Schema::hasTable('sitesetting')) {
            return;
        }

        DB::statement('CREATE TABLE sitesetting (
            SettingKey VARCHAR(80) NOT NULL PRIMARY KEY,
            SettingValue TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}
