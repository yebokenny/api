<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'invoice';

    protected $fillable = [
        'Id',
        'PlanId',
        'PaidAt',
        'Domain',
        'RequestedAmount',
        'Fees',
        'CustomerName',
        'CustomerCode',
        'Channel',
        'GatewayResponse',
        'Reference',
        'PlanCode',
        'PlanInterval',
        'ApplicationUserId',
        'CreationDate',
        'IsDeleted',
        'TransactionId',
        'TenantId',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'PlanId', 'Id');
    }

    public function toPublic(): array
    {
        $currency = (int) ($this->plan?->Currency ?? 0) === 1 ? 'NGN' : 'ZAR';

        return [
            'id' => $this->getKey(),
            'paidAt' => $this->PaidAt,
            'amount' => round(((int) $this->RequestedAmount) / 100, 2),
            'currency' => $currency,
            'plan' => $this->plan?->Name,
            'channel' => $this->Channel,
            'reference' => $this->Reference,
            'status' => 'paid',
        ];
    }
}
