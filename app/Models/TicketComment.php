<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketComment extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'supportticketcomment';

    protected $fillable = [
        'Id', 'SupportTicketId', 'Description', 'IsCustomerResponse', 'CreationDate', 'IsDeleted', 'TenantId',
    ];

    protected function casts(): array
    {
        return [
            'IsCustomerResponse' => 'boolean',
            'IsDeleted' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'SupportTicketId', 'Id');
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->getKey(),
            'message' => $this->Description,
            'fromCustomer' => (bool) $this->IsCustomerResponse,
            'createdAt' => $this->CreationDate,
        ];
    }
}
