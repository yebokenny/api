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

    public static function url(?string $path, ?string $kind = null): string
    {
        if (! is_string($path) || trim($path) === '') {
            return '';
        }
        if (str_starts_with($path, 'data:')) {
            return $path;
        }

        $relative = self::relativePath($path);
        if ($relative === null) {
            return $path;
        }
        if ($kind !== null) {
            $relative = self::placeInFolder($relative, $kind);
        }
        if ($relative === '') {
            return '';
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($relative, '/');
    }

    private static function relativePath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if (preg_match('#^https?://#i', $path)) {
            $parts = parse_url($path);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (! self::isLocalMediaHost($host)) {
                return null;
            }
            $path = (string) ($parts['path'] ?? '');
        }

        return ltrim($path, '/');
    }

    private static function isLocalMediaHost(string $host): bool
    {
        $own = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $hosts = array_filter(['yebo.iaaws.com', 'api.yebokenny.com', '127.0.0.1', 'localhost', $own]);

        return in_array($host, $hosts, true);
    }

    private static function placeInFolder(string $relative, string $kind): string
    {
        $folder = self::FOLDERS[$kind] ?? null;
        if ($folder === null || $relative === '') {
            return $relative;
        }
        if ($relative === $folder || str_starts_with($relative, $folder.'/')) {
            return $relative;
        }
        // Keep files that already live in a nested uploads folder, including uploads/old.
        if (str_starts_with($relative, 'uploads/') && substr_count($relative, '/') >= 2) {
            return $relative;
        }

        return $folder.'/'.basename($relative);
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
