<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessItem extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'businessitem';

    protected $fillable = [
        'Id',
        'BusinessProfileId',
        'BusinessItemType',
        'Name',
        'Description',
        'ImageUrl',
        'Pricing',
        'ExternalUrl',
        'Tags',
        'RecommendationCount',
        'YeboKennyScore',
        'SurveyQ1',
        'SurveyQ2',
        'SurveyQ3',
        'CreationDate',
        'ModificationDate',
        'IsDeleted',
        'TenantId',
    ];

    protected function casts(): array
    {
        return [
            'Pricing' => 'float',
            'YeboKennyScore' => 'float',
            'IsDeleted' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'BusinessProfileId', 'Id');
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->getKey(),
            'businessId' => $this->BusinessProfileId,
            'name' => $this->Name,
            'description' => $this->Description,
            'price' => $this->Pricing !== null ? (float) $this->Pricing : null,
            'url' => $this->ExternalUrl,
            'tags' => $this->Tags,
            'imageUrl' => ($url = \App\Support\MediaStore::url($this->ImageUrl, 'product')) !== '' ? $url : null,
            'yeboScore' => (float) $this->YeboKennyScore,
            'recommendationCount' => (int) $this->RecommendationCount,
        ];
    }
}
