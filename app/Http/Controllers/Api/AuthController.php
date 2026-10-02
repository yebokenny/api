<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Support\BrevoMail;
use App\Support\IdentityPassword;
use App\Support\LegacyCodes;
use App\Support\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:160', 'unique:users,Email'],
            'password' => ['required', 'string', 'min:8'],
            'country' => ['required', 'in:South Africa,Nigeria'],
        ]);

        $plan = Plan::where('IsFree', true)->where('IsEnabled', true)->first();
        $email = $data['email'];

        $user = User::create([
            'Id' => LegacyCodes::id(),
            'FirstName' => $data['firstName'],
            'LastName' => $data['lastName'],
            'UserName' => $email,
            'NormalizedUserName' => Str::upper($email),
            'Email' => $email,
            'NormalizedEmail' => Str::upper($email),
            'EmailConfirmed' => true,
            'PasswordHash' => IdentityPassword::hash($data['password']),
            'SecurityStamp' => Str::uuid()->toString(),
            'ConcurrencyStamp' => Str::uuid()->toString(),
            'PhoneNumberConfirmed' => false,
            'TwoFactorEnabled' => false,
            'LockoutEnabled' => true,
            'AccessFailedCount' => 0,
            'RefreshTokenExpiryTime' => now(),
            'IsLocked' => false,
            'UserCountry' => $data['country'],
            'UserType' => LegacyCodes::USER_BUSINESS,
            'PlanId' => $plan?->getKey(),
            'SubscriptionStatus' => 0,
            'AllowEmailNotifications' => true,
            'ProviderType' => 0,
            'RequestedAccountDeletion' => false,
        ]);

        $user->load('plan');
        BrevoMail::send(
            $user->Email,
            $user->name,
            'Welcome to Yebokenny',
            BrevoMail::html('Welcome to Yebokenny', "Hi {$user->FirstName}, your account is ready. Sign in to add your listing and invite people who have used your business.")
        );

        return response()->json([
            'data' => [
                'token' => $user->issueToken(),
                'user' => $user->toPublic(),
            ],
        ], 201);
    }

    public function forgot(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:160'],
        ]);

        $user = User::where('Email', $data['email'])->first();
        if ($user && ! $user->IsLocked) {
            $link = PasswordReset::link($user);
            BrevoMail::send(
                $user->Email,
                $user->name,
                'Reset your Yebokenny password',
                BrevoMail::html('Reset your password', "Use this link within the next hour to choose a new password:\n\n{$link}\n\nIf you did not ask for this, you can ignore the email.")
            );
        }

        return response()->json([
            'data' => [
                'message' => 'If that email is on Yebokenny, a reset link is on its way.',
            ],
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'uid' => ['required', 'string'],
            'exp' => ['required', 'string'],
            'sig' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = PasswordReset::user($data['uid'], $data['exp'], $data['sig']);
        if (! $user) {
            return response()->json(['message' => 'This reset link is not valid or has expired.'], 422);
        }

        $user->PasswordHash = IdentityPassword::hash($data['password']);
        $user->SecurityStamp = (string) Str::uuid();
        $user->save();
        $user->tokens()->delete();

        return response()->json([
            'data' => ['message' => 'Password updated. You can sign in.'],
        ]);
    }

    public function google(Request $request)
    {
        $clientId = config('services.google.client_id');
        if (! is_string($clientId) || $clientId === '') {
            return response()->json(['message' => 'Google sign-in is not configured yet.'], 422);
        }

        $data = $request->validate([
            'idToken' => ['required', 'string', 'max:4000'],
        ]);

        $response = Http::timeout(10)->acceptJson()->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $data['idToken'],
        ]);
        $payload = $response->json();
        $verified = ($payload['email_verified'] ?? null);
        $confirmed = $verified === true || $verified === 'true';
        if (! $response->ok() || ($payload['aud'] ?? '') !== $clientId || ! $confirmed) {
            return response()->json(['message' => 'Google did not confirm this sign-in.'], 401);
        }

        $user = User::with(['plan.featureRows', 'roles'])->where('Email', $payload['email'] ?? '')->first();
        if (! $user) {
            return response()->json(['message' => 'No Yebokenny account uses that Google email. Register first.'], 422);
        }
        if ($user->IsLocked) {
            return response()->json(['message' => 'This account is disabled.'], 403);
        }

        return response()->json([
            'data' => [
                'token' => $user->issueToken(),
                'user' => $user->toPublic(),
            ],
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($this->tooManyAttempts($data['email'])) {
            return response()->json(['message' => 'Too many sign-in attempts. Wait a few minutes and try again.'], 429);
        }

        $user = User::with(['plan.featureRows', 'roles'])->where('Email', $data['email'])->first();
        if (! $user || ! IdentityPassword::verify($data['password'], $user->PasswordHash)) {
            $this->hitAttempt($data['email']);

            return response()->json(['message' => 'Those details do not match an account.'], 401);
        }
        $this->clearAttempts($data['email']);
        if ($user->IsLocked) {
            return response()->json(['message' => 'This account is disabled.'], 403);
        }

        return response()->json([
            'data' => [
                'token' => $user->issueToken(),
                'user' => $user->toPublic(),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $plain = \App\Http\Middleware\AuthenticateToken::plainToken($request);
        if ($plain) {
            $request->user()->tokens()->where('token', hash('sha256', $plain))->delete();
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    public function me(Request $request)
    {
        $request->user()->loadMissing(['plan.featureRows', 'roles']);

        return response()->json(['data' => $request->user()->toPublic()]);
    }

    private function tooManyAttempts(string $email): bool
    {
        return (int) Cache::store('file')->get($this->attemptKey($email), 0) >= 8;
    }

    private function hitAttempt(string $email): void
    {
        $key = $this->attemptKey($email);
        $cache = Cache::store('file');
        $cache->put($key, (int) $cache->get($key, 0) + 1, now()->addMinutes(10));
    }

    private function clearAttempts(string $email): void
    {
        Cache::store('file')->forget($this->attemptKey($email));
    }

    private function attemptKey(string $email): string
    {
        return 'login:'.sha1(strtolower($email));
    }
}
