<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Support\BrevoMail;
use App\Support\LegacyCodes;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $query = Ticket::with(['user', 'comments'])->where('IsDeleted', false)->orderByDesc('CreationDate');
        if ($request->user()->role === 'owner') {
            $query->where('ApplicationUserId', $request->user()->getKey());
        }

        return response()->json([
            'data' => $query->get()->map->toPublic()->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:140'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $number = ((int) Ticket::max('TicketNumber')) + 1;
        $ticket = Ticket::create([
            'Id' => LegacyCodes::id(),
            'ApplicationUserId' => $request->user()->getKey(),
            'IssueDescription' => $data['subject']."\n\n".$data['message'],
            'SupportTicketStatus' => 0,
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
            'TicketNumber' => $number,
        ]);
        $ticket->load(['user', 'comments']);
        $mailed = $this->notify($ticket, $data['message'], 'New ticket opened');

        return response()->json(['data' => $this->payload($ticket, $mailed)], 201);
    }

    public function show(Request $request, Ticket $ticket)
    {
        if ($denied = $this->deny($request, $ticket)) {
            return $denied;
        }
        $ticket->load(['user', 'comments']);

        return response()->json(['data' => $ticket->toPublic()]);
    }

    public function comment(Request $request, Ticket $ticket)
    {
        if ($denied = $this->deny($request, $ticket)) {
            return $denied;
        }
        if ((int) $ticket->SupportTicketStatus === 2) {
            return response()->json(['message' => 'This ticket is closed.'], 422);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);
        $isCustomer = $request->user()->role !== 'admin';
        TicketComment::create([
            'Id' => LegacyCodes::id(),
            'SupportTicketId' => $ticket->getKey(),
            'Description' => $data['message'],
            'IsCustomerResponse' => $isCustomer,
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
        ]);
        if ($isCustomer) {
            $ticket->SupportTicketStatus = 0;
        } else {
            $ticket->SupportUserId = $request->user()->getKey();
            $ticket->SupportTicketStatus = 1;
        }
        $ticket->ModificationDate = now();
        $ticket->save();
        $ticket->load(['user', 'comments']);
        $mailed = $this->notify($ticket, $data['message'], 'New ticket response');

        return response()->json(['data' => $this->payload($ticket, $mailed)]);
    }

    public function updateStatus(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,waiting,closed'],
        ]);
        $ticket->SupportTicketStatus = match ($data['status']) {
            'waiting' => 1,
            'closed' => 2,
            default => 0,
        };
        if ($data['status'] !== 'open') {
            $ticket->SupportUserId = $ticket->SupportUserId ?: $request->user()->getKey();
        }
        $ticket->ModificationDate = now();
        $ticket->save();
        $ticket->load(['user', 'comments']);

        return response()->json(['data' => $ticket->toPublic()]);
    }

    private function deny(Request $request, Ticket $ticket)
    {
        if ($ticket->IsDeleted) {
            return response()->json(['message' => 'This ticket was not found.'], 404);
        }
        if ($request->user()->role !== 'admin' && $ticket->ApplicationUserId !== $request->user()->getKey()) {
            return response()->json(['message' => 'You do not have access to this ticket.'], 403);
        }

        return null;
    }

    private function notify(Ticket $ticket, string $message, string $heading): bool
    {
        $body = "Ticket #{$ticket->TicketNumber}\n\n{$ticket->IssueDescription}\n\n{$message}";
        $html = BrevoMail::html($heading, $body);
        $subject = $heading;
        $ownerSent = false;
        if ($ticket->user?->Email) {
            $ownerSent = BrevoMail::send($ticket->user->Email, $ticket->user->name, $subject, $html);
        }
        $support = (string) config('services.brevo.support_email');
        $supportSent = $support !== '' && BrevoMail::send($support, 'YeboKenny Support', $subject, $html);

        return $ownerSent && $supportSent;
    }

    private function payload(Ticket $ticket, bool $mailed): array
    {
        $payload = $ticket->toPublic();
        $payload['mailed'] = $mailed;

        return $payload;
    }
}
