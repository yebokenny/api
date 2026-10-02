<?php

namespace App\Support;

use App\Models\MediaImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class MediaStore
{
    public const FOLDERS = [
        'category-icon' => 'uploads/categories/icons',
        'category-header' => 'uploads/categories/headers',
        'business-profile' => 'uploads/businesses/profiles',
        'business-header' => 'uploads/businesses/headers',
        'business-gallery' => 'uploads/businesses/gallery',
        'author' => 'uploads/authors',
        'blog' => 'uploads/blogs',
        'product' => 'uploads/products',
        'review' => 'uploads/reviews',
    ];

    public static function url(?string $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        return rtrim((string) config('app.url'), '/').'/'.$normalized;
    }

    public static function payload(mixed $image): ?array
    {
        if (! $image) {
            return null;
        }
        $original = self::url($image->OriginalUrl ?? null);
        if ($original === '') {
            return null;
        }

        return [
            'id' => (string) ($image->Id ?? $image->getKey()),
            'originalUrl' => $original,
            'thumbnailUrl' => self::url($image->ThumbnailUrl ?? null) ?: $original,
            'smallUrl' => self::url($image->SmallUrl ?? null) ?: $original,
            'mediumUrl' => self::url($image->MediumUrl ?? null) ?: $original,
        ];
    }

    public static function store(UploadedFile $file, string $kind, ?string $ip = null): MediaImage
    {
        $folder = self::FOLDERS[$kind] ?? null;
        if (! $folder) {
            throw ValidationException::withMessages(['file' => 'That image folder is not available.']);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw ValidationException::withMessages(['file' => 'Use a JPG, PNG, or WebP image.']);
        }

        $directory = storage_path('app/'.$folder);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $name = LegacyCodes::id().'.'.$extension;
        $file->move($directory, $name);
        $relative = '/'.$folder.'/'.$name;
        $size = @getimagesize(storage_path('app/'.ltrim($relative, '/'))) ?: [0, 0];
        $url = self::url($relative);

        return MediaImage::create([
            'Id' => LegacyCodes::id(),
            'StrapiId' => 0,
            'Name' => $name,
            'Width' => (int) $size[0],
            'Height' => (int) $size[1],
            'ThumbnailUrl' => $url,
            'OriginalUrl' => $relative,
            'MediumUrl' => $url,
            'SmallUrl' => $url,
            'Provider' => 'local',
            'Mime' => $file->getClientMimeType() ?: 'image/jpeg',
            'Extension' => $extension,
            'ClientIpAddress' => $ip ?: '0.0.0.0',
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
        ]);
    }

    public static function putContents(string $kind, string $binary, string $extension): ?string
    {
        $folder = self::FOLDERS[$kind] ?? null;
        if (! $folder || $binary === '') {
            return null;
        }
        $directory = storage_path('app/'.$folder);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $name = uniqid($kind.'_', true).'.'.$extension;
        file_put_contents($directory.DIRECTORY_SEPARATOR.$name, $binary);

        return $folder.'/'.$name;
    }
}
