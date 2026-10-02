<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'lead';

    protected $fillable = [
        'Id', 'CapturedData', 'Email', 'LeadStatus', 'LeadType', 'CapturedDate', 'CreationDate', 'IsDeleted', 'TenantId', 'RefNo',
    ];

    public function payload(): array
    {
        $decoded = json_decode((string) $this->CapturedData, true);

        return is_array($decoded) ? $decoded : ['message' => (string) $this->CapturedData];
    }

    public function toPublic(): array
    {
        $payload = $this->payload();
        $status = match ((int) $this->LeadStatus) {
            1 => 'responded',
            2, 3 => 'closed',
            default => 'open',
        };
        $type = match ((int) $this->LeadType) {
            1 => 'recommendation',
            2 => 'contact',
            3 => 'feedback',
            default => 'partner',
        };

        return [
            'id' => $this->getKey(),
            'name' => $payload['name'] ?? 'Lead',
            'email' => $this->Email,
            'phone' => $payload['phone'] ?? null,
            'type' => $type,
            'status' => $status,
            'message' => $payload['message'] ?? '',
            'notes' => $payload['notes'] ?? null,
            'createdAt' => $this->CapturedDate,
        ];
    }
}
