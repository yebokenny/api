<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Support\BrevoMail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user()->load('plan');
        $invoices = Invoice::with('plan')
            ->where('ApplicationUserId', $user->getKey())
            ->where('IsDeleted', false)
            ->orderByDesc('PaidAt')
            ->get();

        return response()->json([
            'data' => [
                'past' => $invoices->map->toPublic()->values(),
                'upcoming' => $this->upcoming($user, $invoices->first()),
            ],
        ]);
    }

    public function cancel(Request $request)
    {
        $user = $request->user()->load('plan');
        $plan = $user->plan;
        if (! $plan || $plan->IsFree || (float) $plan->Price <= 0) {
            return response()->json(['message' => 'This account is already on a free plan.'], 422);
        }
        if ((int) $user->SubscriptionStatus === 3) {
            return response()->json(['message' => 'Renewal is already stopped.'], 422);
        }

        $user->SubscriptionStatus = 3;
        $user->save();
        $mailed = BrevoMail::send(
            $user->Email,
            $user->name,
            'Your Yebokenny plan will not renew',
            BrevoMail::html('Renewal stopped', "{$plan->Name} stays active until the current period ends. It will not renew.")
        );
        $user->unsetRelation('plan');
        $user->load(['plan.featureRows', 'roles']);

        return response()->json([
            'data' => [
                'user' => $user->toPublic(),
                'mailed' => $mailed,
            ],
        ]);
    }

    public function webhook(Request $request)
    {
        $body = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');
        $secrets = array_values(array_filter([
            config('services.paystack.secret_zar'),
            config('services.paystack.secret_ngn'),
        ], fn ($secret) => is_string($secret) && $secret !== ''));

        if ($secrets === []) {
            return response()->json(['message' => 'Paystack is not configured yet.'], 503);
        }

        $matched = null;
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha512', $body, $secret), $signature)) {
                $matched = $secret;
                break;
            }
        }
        if ($matched === null) {
            return response()->json(['message' => 'The Paystack signature does not match.'], 401);
        }

        $event = json_decode($body, true);
        if (! is_array($event)) {
            return response()->json(['data' => ['ignored' => true]]);
        }

        $name = (string) ($event['event'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        if ($name === 'charge.success') {
            $this->chargeSuccess($data, $matched);
        } elseif ($name === 'subscription.disable') {
            $this->subscriptionEnded($data, 2);
        } elseif ($name === 'subscription.not_renew') {
            $this->subscriptionEnded($data, 3);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    public function changePlan(Request $request)
    {
        $data = $request->validate([
            'planId' => ['required', 'string', 'exists:plan,Id'],
        ]);

        $user = $request->user()->load('plan');
        $plan = \App\Models\Plan::where('Id', $data['planId'])->where('IsEnabled', true)->first();
        if (! $plan) {
            return response()->json(['message' => 'That plan is not available.'], 422);
        }
        if ($user->PlanId === $plan->getKey()) {
            return response()->json(['message' => 'You are already on this plan.'], 422);
        }

        if ($plan->IsFree || (float) $plan->Price <= 0) {
            $user->PlanId = $plan->getKey();
            $user->SubscriptionStatus = 0;
            $user->save();
            $user->load(['plan.featureRows', 'roles']);

            return response()->json(['data' => ['mode' => 'updated', 'user' => $user->toPublic()]]);
        }

        $currency = (int) $plan->Currency === 1 ? 'NGN' : 'ZAR';
        $secret = $currency === 'NGN'
            ? config('services.paystack.secret_ngn')
            : config('services.paystack.secret_zar');
        if (! $secret || ! $plan->PlanCode) {
            return response()->json(['message' => 'This plan cannot be paid for yet.'], 422);
        }

        $reference = 'YEBOKENNY-'.now()->year.'-'.strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 8));
        $response = Http::withToken($secret)
            ->acceptJson()
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $user->Email,
                'amount' => (int) round(((float) $plan->Price) * 100),
                'currency' => $currency,
                'reference' => $reference,
                'plan' => $plan->PlanCode,
                'callback_url' => rtrim((string) config('services.paystack.portal_url'), '/').'/app/pricing/return',
                'metadata' => [
                    'UserId' => $user->getKey(),
                    'PlanId' => $plan->getKey(),
                ],
            ]);

        $payload = $response->json();
        if (! $response->ok() || empty($payload['data']['authorization_url'])) {
            $message = $payload['message'] ?? 'The payment provider did not start checkout.';
            if (stripos($message, 'invalid key') !== false) {
                $message = 'Checkout could not open because Paystack rejected the payment key.';
            }

            return response()->json(['message' => $message], 422);
        }

        return response()->json([
            'data' => [
                'mode' => 'checkout',
                'checkoutUrl' => $payload['data']['authorization_url'],
                'reference' => $payload['data']['reference'] ?? $reference,
            ],
        ]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
        ]);

        $existing = Invoice::with('plan')->where('Reference', $data['reference'])->where('IsDeleted', false)->first();
        if ($existing && $existing->ApplicationUserId === $request->user()->getKey()) {
            return response()->json(['data' => $existing->toPublic()]);
        }

        $verified = $this->verify($data['reference']);
        if (! $verified) {
            return response()->json(['message' => 'That payment could not be confirmed.'], 422);
        }

        $meta = $verified['metadata'] ?? [];
        $planId = $meta['PlanId'] ?? $meta['plan_id'] ?? null;
        $plan = \App\Models\Plan::find($planId);
        if (! $plan) {
            return response()->json(['message' => 'The paid plan could not be found.'], 422);
        }

        $user = $request->user();
        $customer = $verified['customer']['customer_code'] ?? $user->CustomerCode;
        $invoice = Invoice::create([
            'Id' => \App\Support\LegacyCodes::id(),
            'PlanId' => $plan->getKey(),
            'PaidAt' => isset($verified['paid_at']) ? Carbon::parse($verified['paid_at']) : now(),
            'Domain' => 'yebokenny',
            'RequestedAmount' => (int) ($verified['amount'] ?? 0),
            'Fees' => (int) ($verified['fees'] ?? 0),
            'CustomerName' => $user->name,
            'CustomerCode' => (string) ($customer ?: ''),
            'Channel' => (string) ($verified['channel'] ?? 'card'),
            'GatewayResponse' => (string) ($verified['gateway_response'] ?? 'Approved'),
            'Reference' => $data['reference'],
            'PlanCode' => (string) $plan->PlanCode,
            'PlanInterval' => $this->intervalLabel((int) $plan->PeriodType),
            'ApplicationUserId' => $user->getKey(),
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TransactionId' => (int) ($verified['id'] ?? 0),
            'TenantId' => \App\Support\LegacyCodes::TENANT,
        ]);

        $user->PlanId = $plan->getKey();
        $user->SubscriptionStatus = 1;
        if ($customer) {
            $user->CustomerCode = $customer;
        }
        $user->save();
        $user->unsetRelation('plan');
        $user->load(['plan.featureRows', 'roles']);
        $invoice->load('plan');

        return response()->json(['data' => $invoice->toPublic()]);
    }

    private function chargeSuccess(array $data, string $secret): void
    {
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '' || Invoice::where('Reference', $reference)->where('IsDeleted', false)->exists()) {
            return;
        }

        $response = Http::withToken($secret)->acceptJson()->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));
        $verified = $response->json('data');
        if (! $response->ok() || ($verified['status'] ?? null) !== 'success') {
            Log::warning('Paystack charge could not be verified.', ['reference' => $reference]);

            return;
        }

        $meta = is_array($verified['metadata'] ?? null) ? $verified['metadata'] : [];
        $plan = Plan::find($meta['PlanId'] ?? $meta['plan_id'] ?? null);
        $user = User::find($meta['UserId'] ?? $meta['user_id'] ?? null);
        if (! $plan || ! $user) {
            Log::warning('Paystack charge is missing a plan or user.', ['reference' => $reference]);

            return;
        }

        $customer = $verified['customer']['customer_code'] ?? $user->CustomerCode;
        Invoice::create([
            'Id' => \App\Support\LegacyCodes::id(),
            'PlanId' => $plan->getKey(),
            'PaidAt' => isset($verified['paid_at']) ? Carbon::parse($verified['paid_at']) : now(),
            'Domain' => 'yebokenny',
            'RequestedAmount' => (int) ($verified['amount'] ?? 0),
            'Fees' => (int) ($verified['fees'] ?? 0),
            'CustomerName' => $user->name,
            'CustomerCode' => (string) ($customer ?: ''),
            'Channel' => (string) ($verified['channel'] ?? 'card'),
            'GatewayResponse' => (string) ($verified['gateway_response'] ?? 'Approved'),
            'Reference' => $reference,
            'PlanCode' => (string) ($verified['plan']['plan_code'] ?? $plan->PlanCode),
            'PlanInterval' => (string) ($verified['plan']['interval'] ?? $this->intervalLabel((int) $plan->PeriodType)),
            'ApplicationUserId' => $user->getKey(),
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TransactionId' => (int) ($verified['id'] ?? 0),
            'TenantId' => \App\Support\LegacyCodes::TENANT,
        ]);

        $user->PlanId = $plan->getKey();
        $user->SubscriptionStatus = 1;
        if ($customer) {
            $user->CustomerCode = $customer;
        }
        $user->save();
    }

    private function subscriptionEnded(array $data, int $status): void
    {
        $code = $data['customer']['customer_code'] ?? null;
        $planCode = $data['plan']['plan_code'] ?? null;
        if (! is_string($code) || $code === '' || ! is_string($planCode) || $planCode === '') {
            return;
        }

        $user = User::where('CustomerCode', $code)->first();
        $plan = Plan::where('PlanCode', $planCode)->first();
        if (! $user || ! $plan || $user->PlanId !== $plan->getKey()) {
            return;
        }

        $free = Plan::where('IsFree', true)->where('IsEnabled', true)->first();
        if ($free) {
            $user->PlanId = $free->getKey();
        }
        $user->SubscriptionStatus = $status;
        $user->save();
    }

    private function verify(string $reference): ?array
    {
        foreach ([config('services.paystack.secret_zar'), config('services.paystack.secret_ngn')] as $secret) {
            if (! $secret) {
                continue;
            }
            $response = Http::withToken($secret)->acceptJson()->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));
            $payload = $response->json();
            if ($response->ok() && ($payload['data']['status'] ?? null) === 'success') {
                return $payload['data'];
            }
        }

        return null;
    }

    private function upcoming($user, ?Invoice $latest): ?array
    {
        $plan = $user->plan;
        if (! $plan || $plan->IsFree || (float) $plan->Price <= 0) {
            return null;
        }

        $months = match ((int) $plan->PeriodType) {
            3 => 1,
            6 => 3,
            5 => 12,
            default => null,
        };
        if ($months === null) {
            return null;
        }

        $due = $latest ? Carbon::parse($latest->PaidAt)->addMonths($months) : now();
        $currency = (int) $plan->Currency === 1 ? 'NGN' : 'ZAR';

        return [
            'plan' => $plan->Name,
            'amount' => (float) $plan->Price,
            'currency' => $currency,
            'dueAt' => $due->toDateString(),
            'status' => $due->isFuture() ? 'scheduled' : 'due',
        ];
    }

    private function intervalLabel(int $period): string
    {
        return match ($period) {
            3 => 'monthly',
            5 => 'annually',
            6 => 'quarterly',
            default => 'once',
        };
    }
}
