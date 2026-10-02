<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Support\BrevoMail;
use App\Support\LegacyCodes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function index()
    {
        $leads = Lead::where('IsDeleted', false)->orderByDesc('CapturedDate')->get();

        return response()->json([
            'data' => $leads->map->toPublic()->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $type = match ($data['type'] ?? 'contact') {
            'recommendation' => 1,
            'partner' => 0,
            'feedback' => 3,
            default => 2,
        };

        $lead = Lead::create([
            'Id' => LegacyCodes::id(),
            'CapturedData' => json_encode([
                'name' => $data['name'],
                'message' => $data['message'] ?? '',
                'phone' => $data['phone'] ?? null,
            ]),
            'Email' => $data['email'],
            'LeadStatus' => 0,
            'LeadType' => $type,
            'CapturedDate' => now(),
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
            'RefNo' => 'LD-'.now()->format('ymdHis'),
        ]);

        return response()->json(['data' => $lead->toPublic()], 201);
    }

    public function update(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:open,responded,closed'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payload = $lead->payload();
        if (array_key_exists('notes', $data)) {
            $payload['notes'] = $data['notes'];
        }
        if (isset($data['status'])) {
            $lead->LeadStatus = match ($data['status']) {
                'responded' => 1,
                'closed' => 2,
                default => 0,
            };
        }
        $lead->CapturedData = json_encode($payload);
        $lead->ModificationDate = now();
        $lead->save();

        return response()->json(['data' => $lead->toPublic()]);
    }

    public function mail(Request $request, Lead $lead)
    {
        if ($lead->IsDeleted) {
            return response()->json(['message' => 'This lead was not found.'], 404);
        }
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);
        if (! $lead->Email) {
            return response()->json(['message' => 'This lead has no email address.'], 422);
        }
        if (! BrevoMail::configured()) {
            throw ValidationException::withMessages([
                'message' => 'Add a Brevo API key before sending mail.',
            ]);
        }

        $name = (string) ($lead->payload()['name'] ?? '');
        $sent = BrevoMail::send(
            $lead->Email,
            $name,
            'Reply from Yebokenny',
            BrevoMail::html('Reply from Yebokenny', $data['message'])
        );
        if (! $sent) {
            throw ValidationException::withMessages([
                'message' => 'Brevo did not accept this message.',
            ]);
        }

        $payload = $lead->payload();
        $payload['reply-'.now()->format('ymdHi')] = $data['message'];
        $lead->CapturedData = json_encode($payload);
        $lead->LeadStatus = 1;
        $lead->ModificationDate = now();
        $lead->save();

        return response()->json(['data' => $lead->toPublic()]);
    }
}
