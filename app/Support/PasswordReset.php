<?php

namespace App\Support;

use App\Models\User;

class PasswordReset
{
    public static function link(User $user): string
    {
        $expires = now()->addHour()->getTimestamp();
        $signature = self::sign($user, $expires);
        $base = rtrim((string) config('services.paystack.portal_url'), '/');

        return $base.'/reset?uid='.rawurlencode((string) $user->getKey()).'&exp='.$expires.'&sig='.$signature;
    }

    public static function user(string $id, string $expires, string $signature): ?User
    {
        if (! ctype_digit($expires) || (int) $expires < time()) {
            return null;
        }

        $user = User::find($id);
        if (! $user || $user->IsLocked) {
            return null;
        }

        if (! hash_equals(self::sign($user, (int) $expires), $signature)) {
            return null;
        }

        return $user;
    }

    private static function sign(User $user, int $expires): string
    {
        $stamp = substr(hash('sha256', (string) $user->PasswordHash), 0, 16);
        $payload = $user->getKey().'|'.$expires.'|'.$stamp;

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
