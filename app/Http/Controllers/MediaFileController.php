<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaFileController extends Controller
{
    public function show(Request $request, string $path): BinaryFileResponse
    {
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            abort(404);
        }

        $base = realpath(storage_path('app/uploads'));
        $full = realpath(storage_path('app/uploads/'.$relative));
        if ($base === false || $full === false || ! is_file($full) || ! str_starts_with($full, $base.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $type = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return response()->file($full, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
