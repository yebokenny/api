<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateToken;
use App\Models\ApiToken;
use App\Models\Blog;
use App\Models\User;
use App\Support\IdentityPassword;
use Illuminate\Http\Request;

class LegacyBlogController extends Controller
{
    public function login(Request $request)
    {
        $user = User::with(['plan.featureRows', 'roles'])->where('Email', $request->input('email'))->first();
        if (! $user || ! IdentityPassword::verify((string) $request->input('password'), $user->PasswordHash) || $user->role !== 'admin') {
            return response()->json(['code' => 401, 'message' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Login successful',
            'token' => $user->issueToken(),
        ]);
    }

    public function logout(Request $request)
    {
        $plain = AuthenticateToken::plainToken($request);
        if ($plain) {
            ApiToken::where('token', hash('sha256', $plain))->delete();
        }

        return response()->json(['code' => 200, 'message' => 'Signed out']);
    }

    public function blogs()
    {
        return response()->json([
            'code' => 200,
            'blogs' => Blog::where('status', 'Active')->latest()->get()->map(fn (Blog $blog) => $this->row($blog))->values(),
        ]);
    }

    public function blogsAdmin()
    {
        return response()->json([
            'code' => 200,
            'blogs' => Blog::latest()->get()->map(fn (Blog $blog) => $this->row($blog))->values(),
        ]);
    }

    public function details(Request $request)
    {
        $blog = Blog::with('comments')->find($request->query('id'));
        if (! $blog) {
            return response()->json(['code' => 404, 'message' => 'Blog not found'], 404);
        }

        return response()->json([
            'code' => 200,
            'blog' => $this->row($blog, true),
            'comments' => $blog->comments->map(fn ($comment) => [
                'id' => $comment->id,
                'blog_id' => $comment->blog_id,
                'name' => $comment->name,
                'comment' => $comment->comment,
                'created_at' => $comment->created_at,
            ])->values(),
        ]);
    }

    public function view(Request $request)
    {
        $blog = Blog::find($request->query('blog_id'));
        if ($blog) {
            $blog->increment('views');
        }

        return response()->json(['code' => 200]);
    }

    public function like(Request $request)
    {
        $blog = Blog::find($request->query('blog_id'));
        if ($blog) {
            if ($request->query('type') === 'minus') {
                $blog->likes = max(0, (int) $blog->likes - 1);
                $blog->save();
            } else {
                $blog->increment('likes');
            }
        }

        return response()->json(['code' => 200]);
    }

    public function create(Request $request)
    {
        $admin = $this->admin($request);
        if (! $admin) {
            return response()->json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }

        $blog = Blog::create([
            'user_id' => $admin->getKey(),
            'title' => (string) $request->input('title'),
            'category' => (string) $request->input('category'),
            'details' => (string) $request->input('details'),
            'image_path' => $this->storeImage($request->input('image')),
            'status' => 'Active',
        ]);

        return response()->json(['code' => 200, 'message' => 'Blog created', 'id' => $blog->id]);
    }

    public function update(Request $request)
    {
        if (! $this->admin($request)) {
            return response()->json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }
        $blog = Blog::find($request->input('id'));
        if (! $blog) {
            return response()->json(['code' => 404, 'message' => 'Blog not found'], 404);
        }
        $blog->fill([
            'title' => $request->input('title', $blog->title),
            'category' => $request->input('category', $blog->category),
            'details' => $request->input('details', $blog->details),
            'status' => $request->input('status', $blog->status),
        ]);
        if ($request->filled('image')) {
            $blog->image_path = $this->storeImage($request->input('image')) ?? $blog->image_path;
        }
        $blog->save();

        return response()->json(['code' => 200, 'message' => 'Blog updated']);
    }

    public function destroy(Request $request)
    {
        if (! $this->admin($request)) {
            return response()->json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }
        Blog::where('id', $request->input('id'))->delete();

        return response()->json(['code' => 200, 'message' => 'Blog deleted']);
    }

    public function uploadReview(Request $request)
    {
        $paths = [];
        foreach (['one', 'two'] as $key) {
            $stored = $this->storeImage($request->input($key), 'review');
            if ($stored) {
                $paths[] = $stored;
            }
        }

        return response()->json([
            'code' => 200,
            'data' => array_map(fn (string $path) => \App\Support\MediaStore::url($path, 'review'), $paths),
        ]);
    }

    public function comment(Request $request)
    {
        $blog = Blog::find($request->input('blog_id'));
        if (! $blog) {
            return response()->json(['code' => 404, 'message' => 'Blog not found'], 404);
        }
        $comment = $blog->comments()->create([
            'name' => (string) $request->input('name'),
            'comment' => (string) $request->input('comment'),
            'status' => 'Active',
        ]);
        $blog->increment('comments_count');

        return response()->json(['code' => 200, 'id' => $comment->id]);
    }

    public function password(Request $request)
    {
        $admin = $this->admin($request);
        if (! $admin) {
            return response()->json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }
        if (! IdentityPassword::verify((string) $request->input('current_password'), $admin->PasswordHash)) {
            return response()->json(['code' => 422, 'message' => 'Current password is not correct'], 422);
        }
        $admin->PasswordHash = IdentityPassword::hash((string) $request->input('new_password'));
        $admin->save();

        return response()->json(['code' => 200, 'message' => 'Password updated']);
    }

    private function admin(Request $request): ?User
    {
        $plain = AuthenticateToken::plainToken($request);
        if (! $plain) {
            return null;
        }
        $record = ApiToken::with('user.roles')->where('token', hash('sha256', $plain))->where('expires_at', '>', now())->first();
        $user = $record?->user;
        if (! $user || $user->role !== 'admin') {
            return null;
        }

        return $user;
    }

    private function row(Blog $blog, bool $withBody = false): array
    {
        $row = [
            'id' => $blog->id,
            'title' => $blog->title,
            'category' => $blog->category,
            'image_path' => \App\Support\MediaStore::url($blog->image_path, 'blog'),
            'status' => $blog->status,
            'likes' => (int) $blog->likes,
            'views' => (int) $blog->views,
            'comments' => (int) $blog->comments_count,
            'admin_id' => $blog->user_id,
            'created_at' => $blog->created_at,
        ];
        if ($withBody) {
            $row['details'] = $blog->details;
        }

        return $row;
    }

    private function storeImage(mixed $image, string $kind = 'blog'): ?string
    {
        if (! is_string($image) || $image === '') {
            return null;
        }
        $binary = base64_decode($image, true);
        if ($binary === false) {
            return null;
        }
        $info = @getimagesizefromstring($binary);
        $extension = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
        if (! $extension) {
            return null;
        }
        return \App\Support\MediaStore::putContents($kind, $binary, $extension);
    }
}
