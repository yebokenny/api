<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'supportticket';

    protected $fillable = [
        'Id', 'ApplicationUserId', 'SupportUserId', 'IssueDescription', 'SupportTicketStatus', 'CreationDate', 'ModificationDate', 'IsDeleted', 'TenantId', 'TicketNumber',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ApplicationUserId', 'Id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'SupportTicketId', 'Id')
            ->where('IsDeleted', false)
            ->orderBy('CreationDate');
    }

    public function toPublic(): array
    {
        $text = (string) $this->IssueDescription;
        $subject = 'Ticket '.$this->TicketNumber;
        $message = $text;
        if (str_contains($text, "\n\n")) {
            [$subject, $message] = explode("\n\n", $text, 2);
        }
        $status = match ((int) $this->SupportTicketStatus) {
            1 => 'waiting',
            2 => 'closed',
            default => 'open',
        };

        return [
            'id' => $this->getKey(),
            'subject' => $subject,
            'message' => $message,
            'status' => $status,
            'createdAt' => $this->CreationDate,
            'ticketNumber' => (int) $this->TicketNumber,
            'owner' => $this->user?->name,
            'ownerEmail' => $this->user?->Email,
            'comments' => ($this->relationLoaded('comments') ? $this->comments : $this->comments()->get())
                ->map(fn (TicketComment $comment) => $comment->toPublic())
                ->values()
                ->all(),
        ];
    }
}
