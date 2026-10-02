<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\MediaStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'category';

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'CategoryId', 'Id');
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class, 'SurveyId', 'Id');
    }

    public function icon(): BelongsTo
    {
        return $this->belongsTo(MediaImage::class, 'CategoryImageId', 'Id');
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(MediaImage::class, 'CategoryHeaderId', 'Id');
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->CategoryName,
            'slug' => Str::slug($this->CategoryName) ?: 'category',
            'defaultQuestions' => $this->survey?->questions() ?? [],
            'businessCount' => $this->businesses_count ?? $this->businesses()->count(),
            'image' => MediaStore::payload($this->relationLoaded('icon') ? $this->icon : $this->icon()->first()),
            'header' => MediaStore::payload($this->relationLoaded('header') ? $this->header : $this->header()->first()),
        ];
    }
}
