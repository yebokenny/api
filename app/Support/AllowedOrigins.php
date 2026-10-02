<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AllowedOrigins
{
    public static function all(): array
    {
        $stored = self::stored();

        return $stored ?? array_values(config('cors.allowed_origins', []));
    }

    public static function apply(): void
    {
        $stored = self::stored();
        if ($stored !== null) {
            config(['cors.allowed_origins' => $stored]);
        }
    }

    /**
     * @param  list<mixed>  $origins
     * @return list<string>
     */
    public static function replace(array $origins, string $caller): array
    {
        $clean = [];
        foreach ($origins as $origin) {
            if (! is_string($origin)) {
                throw ValidationException::withMessages([
                    'origins' => 'Each origin must be a web address.',
                ]);
            }
            $normalized = self::normalize($origin);
            if ($normalized === null) {
                throw ValidationException::withMessages([
                    'origins' => 'Use a full address such as https://yebokenny.com, with no path.',
                ]);
            }
            $clean[$normalized] = $normalized;
        }

        $clean = array_values($clean);
        if ($clean === []) {
            throw ValidationException::withMessages([
                'origins' => 'Keep at least one origin.',
            ]);
        }
        if (count($clean) > 50) {
            throw ValidationException::withMessages([
                'origins' => 'Use 50 origins or fewer.',
            ]);
        }

        $caller = self::normalize($caller);
        if ($caller === null || ! in_array($caller, $clean, true)) {
            throw ValidationException::withMessages([
                'origins' => 'Keep the address of this admin desk in the list.',
            ]);
        }

        DB::transaction(function () use ($clean) {
            DB::table('allowedorigin')->delete();
            foreach ($clean as $origin) {
                DB::table('allowedorigin')->insert(['Origin' => $origin]);
            }
        });

        config(['cors.allowed_origins' => $clean]);

        return $clean;
    }

    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '*') || preg_match('/\s/', $value)) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '') {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    /**
     * @return list<string>|null
     */
    private static function stored(): ?array
    {
        try {
            $rows = DB::table('allowedorigin')->orderBy('Id')->pluck('Origin');
        } catch (QueryException) {
            return null;
        }

        $origins = $rows->map(fn ($origin) => (string) $origin)->filter()->values()->all();

        return $origins === [] ? null : $origins;
    }
}
