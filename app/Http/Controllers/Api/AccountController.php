<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BrevoMail;
use App\Support\IdentityPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['required', 'in:South Africa,Nigeria'],
        ]);

        $user->FirstName = $data['firstName'];
        $user->LastName = $data['lastName'];
        $user->PhoneNumber = $data['phone'] ?? null;
        $user->UserCountry = $data['country'];
        $user->save();
        $user->load(['plan.featureRows', 'roles']);

        return response()->json(['data' => $user->toPublic()]);
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        if (! IdentityPassword::verify($data['currentPassword'], $user->PasswordHash)) {
            return response()->json(['message' => 'The current password does not match.'], 422);
        }

        $user->PasswordHash = IdentityPassword::hash($data['password']);
        $user->SecurityStamp = (string) Str::uuid();
        $user->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function deactivation(Request $request)
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! IdentityPassword::verify($data['password'], $user->PasswordHash)) {
            return response()->json(['message' => 'The password does not match.'], 422);
        }
        if ($user->RequestedAccountDeletion) {
            return response()->json(['message' => 'A deactivation request is already waiting.'], 422);
        }

        $user->RequestedAccountDeletion = true;
        $user->save();
        $support = (string) config('services.brevo.support_email');
        $mailed = BrevoMail::send(
            $support,
            'Yebokenny support',
            'Account deactivation requested',
            BrevoMail::html('Account deactivation', "{$user->name} ({$user->Email}) asked to deactivate their account.")
        );
        BrevoMail::send(
            $user->Email,
            $user->name,
            'We received your deactivation request',
            BrevoMail::html('Deactivation requested', 'We will be in touch at this email address before the account is closed.')
        );
        $user->load(['plan.featureRows', 'roles']);

        return response()->json([
            'data' => [
                'user' => $user->toPublic(),
                'mailed' => $mailed,
            ],
        ]);
    }
}
