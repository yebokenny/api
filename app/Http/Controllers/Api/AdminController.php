<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Lead;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AllowedOrigins;
use App\Support\BrevoMail;
use App\Support\LegacyCodes;
use App\Support\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function summary()
    {
        return response()->json([
            'data' => [
                'businesses' => Business::where('IsDeleted', false)->count(),
                'owners' => User::whereDoesntHave('roles', fn ($q) => $q->where('Name', 'Admin'))->count(),
                'openLeads' => Lead::where('IsDeleted', false)->where('LeadStatus', 0)->count(),
                'openTickets' => Ticket::where('IsDeleted', false)->where('SupportTicketStatus', 0)->count(),
                'score' => (float) Business::where('IsDeleted', false)->sum('YeboKennyScore'),
                'feedbacks' => (int) DB::table('feedback')->where('IsDeleted', false)->count(),
                'businessesByPlan' => $this->groupedBusinesses("COALESCE(plan.Name, 'No plan')"),
                'businessesByCountry' => $this->groupedBusinesses("COALESCE(NULLIF(users.UserCountry, ''), 'South Africa')"),
            ],
        ]);
    }

    public function businesses()
    {
        $rows = Business::with(['category.icon', 'category.header', 'owner', 'amenities', 'hours', 'profileImage', 'headerImage', 'gallery'])->where('IsDeleted', false)->orderByDesc('CreationDate')->get();

        return response()->json([
            'data' => $rows->map(function (Business $business) {
                $payload = $business->toPublic();
                $payload['ownerEmail'] = $business->owner?->Email;

                return $payload;
            })->values(),
        ]);
    }

    public function business(Business $business)
    {
        if ($business->IsDeleted) {
            return response()->json(['message' => 'This listing was not found.'], 404);
        }

        return response()->json(['data' => $this->presentBusiness($business)]);
    }

    public function updateBusinessStatus(Request $request, Business $business)
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,accepted,rejected'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($business->IsDeleted) {
            return response()->json(['message' => 'This listing was not found.'], 404);
        }
        $business->loadMissing('owner');
        if ((int) $business->owner?->UserType === 21938) {
            return response()->json(['message' => 'A test listing cannot change status.'], 422);
        }

        $business->ReviewStatus = match ($data['status']) {
            'pending' => LegacyCodes::REVIEW_PENDING,
            'rejected' => LegacyCodes::REVIEW_REJECTED,
            default => LegacyCodes::REVIEW_ACCEPTED,
        };
        $business->ReviewComments = $data['comments'] ?? '';
        $business->save();
        $business->load(['category.icon', 'category.header', 'owner', 'amenities', 'hours', 'products', 'profileImage', 'headerImage', 'gallery']);

        $mailed = false;
        if ($business->owner?->Email) {
            $mailed = BrevoMail::send(
                $business->owner->Email,
                $business->owner->name,
                $business->Name.' status changed: '.$data['status'],
                BrevoMail::html(
                    $business->Name.' is now '.$data['status'],
                    trim("Hello {$business->owner->name}\n\nThe listing status is now {$data['status']}.\n\n".($data['comments'] ?? ''))
                ),
            );
        }

        $payload = $this->presentBusiness($business);
        $payload['mailed'] = $mailed;

        return response()->json(['data' => $payload]);
    }

    public function mailBusiness(Request $request, Business $business)
    {
        if (! $business->EmailAddress) {
            return response()->json(['message' => 'This listing has no email address.'], 422);
        }

        $this->sendMail($request, $business->EmailAddress, $business->Name);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function users()
    {
        $users = User::with(['plan.featureRows', 'roles'])->orderBy('FirstName')->get();

        return response()->json([
            'data' => $users->map->toPublic()->values(),
        ]);
    }

    public function user(User $user)
    {
        $user->load([
            'plan.featureRows',
            'roles',
            'businesses' => fn ($query) => $query->where('IsDeleted', false)->with('category')->orderByDesc('CreationDate'),
        ]);
        $payload = $user->toPublic();
        $payload['listings'] = $user->businesses->map(function (Business $business) {
            return [
                'id' => $business->getKey(),
                'name' => $business->Name,
                'reviewStatus' => LegacyCodes::reviewLabel((int) $business->ReviewStatus),
                'yeboScore' => (float) $business->YeboKennyScore,
                'category' => $business->category?->CategoryName,
                'email' => $business->EmailAddress,
                'website' => $business->Website,
            ];
        })->values();

        return response()->json(['data' => $payload]);
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'locked' => ['required', 'boolean'],
        ]);
        if ($user->getKey() === $request->user()->getKey()) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }
        $user->IsLocked = $data['locked'];
        $user->save();
        if ($data['locked']) {
            $user->tokens()->delete();
        }
        $user->load(['plan.featureRows', 'roles']);

        return response()->json(['data' => $user->toPublic()]);
    }

    public function mailUser(Request $request, User $user)
    {
        if (! $user->Email) {
            return response()->json(['message' => 'This user has no email address.'], 422);
        }
        $this->sendMail($request, $user->Email, $user->name);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function origins()
    {
        return response()->json([
            'data' => AllowedOrigins::all(),
        ]);
    }

    public function updateOrigins(Request $request)
    {
        $payload = $request->validate([
            'origins' => ['required', 'array', 'min:1', 'max:50'],
        ]);
        $caller = $request->headers->get('Origin');
        if (! is_string($caller) || $caller === '') {
            throw ValidationException::withMessages([
                'origins' => 'Save this list from the admin desk.',
            ]);
        }

        return response()->json([
            'data' => AllowedOrigins::replace($payload['origins'], $caller),
        ]);
    }

    public function destroyBusiness(Business $business)
    {
        if ($business->IsDeleted) {
            return response()->json(['message' => 'This listing was already removed.'], 404);
        }

        $business->IsDeleted = true;
        $business->DeletionDate = now();
        $business->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function transferBusiness(Request $request, Business $business)
    {
        if ($business->IsDeleted) {
            return response()->json(['message' => 'This listing was already removed.'], 404);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:160'],
        ]);
        $next = User::where('Email', $data['email'])->first();
        if (! $next) {
            return response()->json(['message' => 'No account uses that email.'], 422);
        }
        if ($next->IsLocked) {
            return response()->json(['message' => 'That account is disabled.'], 422);
        }
        if ($business->ApplicationUserId === $next->getKey()) {
            return response()->json(['message' => 'This listing already belongs to that account.'], 422);
        }

        $previous = User::find($business->ApplicationUserId);
        $business->ApplicationUserId = $next->getKey();
        $business->save();
        $business->unsetRelation('owner');

        $mailed = false;
        if (BrevoMail::configured()) {
            $mailed = BrevoMail::send(
                $next->Email,
                $next->name,
                'A listing was transferred to you',
                BrevoMail::html('Listing transferred', "{$business->Name} is now on your Yebokenny account.")
            );
            if ($previous && $previous->Email) {
                BrevoMail::send(
                    $previous->Email,
                    $previous->name,
                    'A listing left your account',
                    BrevoMail::html('Listing transferred', "{$business->Name} was moved to another Yebokenny account.")
                );
            }
        }

        $payload = $this->presentBusiness($business);
        $payload['mailed'] = $mailed;

        return response()->json(['data' => $payload]);
    }

    public function site()
    {
        return response()->json(['data' => SiteSettings::all()]);
    }

    public function settings()
    {
        return response()->json(['data' => SiteSettings::all()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'maintenance' => ['required', 'boolean'],
            'maintenanceEnds' => ['nullable', 'string', 'max:80'],
            'websiteUrl' => ['required', 'string', 'max:255'],
            'adminUrl' => ['required', 'string', 'max:255'],
            'googleLogin' => ['required', 'boolean'],
            'scoreReports' => ['required', 'boolean'],
        ]);

        return response()->json(['data' => SiteSettings::replace($data)]);
    }

    private function presentBusiness(Business $business): array
    {
        $business->loadMissing(['category.icon', 'category.header', 'owner', 'amenities', 'hours', 'products', 'profileImage', 'headerImage', 'gallery']);
        $payload = $business->toPublic();
        $payload['ownerEmail'] = $business->owner?->Email;
        $payload['reviewComments'] = $business->ReviewComments;
        $payload['createdAt'] = $business->CreationDate;

        return $payload;
    }

    private function groupedBusinesses(string $expression): array
    {
        $rows = Business::query()
            ->where('businessprofile.IsDeleted', false)
            ->leftJoin('users', 'users.Id', '=', 'businessprofile.ApplicationUserId')
            ->leftJoin('plan', 'plan.Id', '=', 'users.PlanId')
            ->selectRaw($expression.' as label');

        return DB::query()
            ->fromSub($rows, 'grouped')
            ->selectRaw('label, COUNT(*) as total')
            ->groupBy('label')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'total' => (int) $row->total])
            ->values()
            ->all();
    }

    private function sendMail(Request $request, string $email, string $name): void
    {
        if (! BrevoMail::configured()) {
            throw ValidationException::withMessages([
                'message' => 'Add a Brevo API key before sending mail.',
            ]);
        }
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:140'],
            'message' => ['required', 'string', 'max:4000'],
        ]);
        $sent = BrevoMail::send($email, $name, $data['subject'], BrevoMail::html($data['subject'], $data['message']));
        if (! $sent) {
            throw ValidationException::withMessages([
                'message' => 'Brevo did not accept this message.',
            ]);
        }
    }
}
