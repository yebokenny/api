<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Author extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'authorinformation';

    protected $fillable = [
        'Id',
        'ApplicationUserId',
        'DisplayName',
        'JobTitle',
        'Hobbies',
        'Description',
        'AuthorInformationImageId',
        'IsVisible',
        'CreationDate',
        'ModificationDate',
        'IsDeleted',
        'TenantId',
    ];

    protected function casts(): array
    {
        return [
            'IsVisible' => 'boolean',
            'IsDeleted' => 'boolean',
        ];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaImage::class, 'AuthorInformationImageId', 'Id');
    }

    public function toPublic(bool $forOwner = false): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->DisplayName,
            'profession' => $this->JobTitle,
            'about' => trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->Description)) ?? ''),
            'hobbies' => $this->Hobbies,
            'photo' => \App\Support\MediaStore::url($this->image?->OriginalUrl) ?: null,
            'isVisible' => (bool) $this->IsVisible,
            'shownToVisitors' => $forOwner ? (bool) $this->IsVisible : null,
        ];
    }
}
