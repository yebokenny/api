<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationInvite extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'usersrecommendation';

    protected $fillable = [
        'Id', 'BusinessProfileId', 'Email', 'Token', 'CreatedAt', 'CreationDate', 'IsDeleted', 'TenantId',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'BusinessProfileId', 'Id');
    }
}
