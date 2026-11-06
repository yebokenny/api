<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Blog extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'category',
        'details',
        'image_path',
        'status',
        'likes',
        'views',
        'comments_count',
        'admin_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Blog $blog) {
            if ($blog->admin_id !== null && $blog->admin_id !== '') {
                return;
            }
            if (! Schema::hasColumn($blog->getTable(), 'admin_id')) {
                return;
            }
            $blog->admin_id = $blog->user_id;
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'Id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BlogComment::class);
    }

    public function toPublic(bool $withBody = false): array
    {
        $payload = [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'status' => $this->status,
            'likes' => $this->likes,
            'views' => $this->views,
            'comments' => (int) $this->comments_count,
            'imagePath' => \App\Support\MediaStore::url($this->image_path, 'blog'),
            'author' => $this->author?->name,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];

        if ($withBody) {
            $payload['details'] = $this->details;
        }

        return $payload;
    }
}
