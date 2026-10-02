<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'recommendation';

    protected $fillable = [
        'Id', 'IsRecommended', 'SurveyDataId', 'BusinessProfileId', 'IPAddress', 'FingerPrint',
        'Platform', 'Latitude', 'Longitude', 'CreationDate', 'IsDeleted', 'IsComputed',
        'AnonymousUserId', 'IsDuplicate', 'TenantId',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'BusinessProfileId', 'Id');
    }

    public function anonymousUser(): BelongsTo
    {
        return $this->belongsTo(AnonymousUser::class, 'AnonymousUserId', 'Id');
    }
}
