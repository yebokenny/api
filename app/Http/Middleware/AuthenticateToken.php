<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateToken
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $plain = self::plainToken($request);
        if (! $plain) {
            return response()->json(['message' => 'Sign in to continue.'], 401);
        }

        $record = ApiToken::with(['user.plan.featureRows', 'user.roles'])
            ->where('token', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->first();

        if (! $record || ! $record->user || $record->user->IsLocked) {
            return response()->json(['message' => 'Your session has ended. Sign in again.'], 401);
        }

        if ($roles && ! in_array($record->user->role, $roles, true)) {
            return response()->json(['message' => 'You do not have access to this area.'], 403);
        }

        $request->setUserResolver(fn () => $record->user);

        return $next($request);
    }

    public static function plainToken(Request $request): ?string
    {
        $plain = $request->bearerToken();
        if ($plain) {
            return $plain;
        }

        $custom = $request->header('X-Auth-Token');
        if (! is_string($custom) || $custom === '') {
            return null;
        }

        return preg_replace('/^Bearer\s+/i', '', $custom) ?: null;
    }
}
