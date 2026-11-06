<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Support\MediaStore;
use App\Support\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BlogController extends Controller
{
    public function index(Request $request)
    {
        $query = Blog::with('author')->latest();
        if (! $request->boolean('all')) {
            $query->where('status', 'Active');
        }

        return response()->json([
            'data' => $query->get()->map(fn (Blog $blog) => $blog->toPublic())->values(),
        ]);
    }

    public function show(Blog $blog)
    {
        $blog->load('author');
        $payload = $blog->toPublic(true);
        $payload['commentList'] = $blog->comments()->latest()->get()->map(fn ($comment) => [
            'id' => $comment->id,
            'name' => $comment->name,
            'comment' => $comment->comment,
            'createdAt' => $comment->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $payload]);
    }

    public function recordView(Blog $blog)
    {
        $blog->increment('views');

        return response()->json(['data' => ['views' => (int) $blog->fresh()->views]]);
    }

    public function likes(Blog $blog)
    {
        return response()->json(['data' => ['likes' => (int) $blog->likes]]);
    }

    public function like(Request $request, Blog $blog)
    {
        if ($request->input('type') === 'minus') {
            $blog->likes = max(0, (int) $blog->likes - 1);
            $blog->save();
        } else {
            $blog->increment('likes');
        }

        return response()->json(['data' => ['likes' => (int) $blog->fresh()->likes]]);
    }

    public function comment(Request $request, Blog $blog)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'comment' => ['required', 'string', 'max:5000'],
        ]);

        $comment = $blog->comments()->create([
            'name' => $data['name'],
            'comment' => $data['comment'],
            'status' => 'Active',
        ]);
        $blog->increment('comments_count');

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'name' => $comment->name,
                'comment' => $comment->comment,
                'createdAt' => $comment->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function categories()
    {
        return response()->json(['data' => SiteSettings::blogCategories()]);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);
        $name = trim((string) preg_replace('/\s+/u', ' ', $data['name']));
        if ($name === '') {
            return response()->json(['message' => 'Enter a category name.'], 422);
        }
        foreach (SiteSettings::blogCategories() as $category) {
            if (strcasecmp($category, $name) === 0) {
                return response()->json(['message' => 'This category already exists.'], 422);
            }
        }

        return response()->json(['data' => SiteSettings::addBlogCategory($name)], 201);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:80'],
            'details' => ['required', 'string'],
            'status' => ['nullable', 'in:Active,Draft'],
        ]);

        $blog = Blog::create([
            ...$data,
            'user_id' => $request->user()->getKey(),
            'status' => $data['status'] ?? 'Active',
        ]);
        $blog->load('author');

        return response()->json(['data' => $blog->toPublic(true)], 201);
    }

    public function update(Request $request, Blog $blog)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'category' => ['sometimes', 'string', 'max:80'],
            'details' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:Active,Draft'],
        ]);

        $blog->fill($data)->save();
        $blog->load('author');

        return response()->json(['data' => $blog->toPublic(true)]);
    }

    public function image(Request $request, Blog $blog)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $image = MediaStore::store($request->file('file'), 'blog', $request->ip());
        $blog->image_path = $image->OriginalUrl;
        $blog->save();
        $blog->load('author');

        return response()->json(['data' => $blog->toPublic(true)]);
    }

    public function destroy(Blog $blog)
    {
        $id = $blog->getKey();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (['comments', 'blog_comments', 'blog_views', 'blog_likes'] as $table) {
                $this->deleteMatchingRows($table, 'blog_id', $id);
            }
            $this->deleteReferencingRows('blogs', 'id', $id);
            DB::table('blogs')->where('id', $id)->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    private function deleteReferencingRows(string $table, string $column, mixed $value, int $depth = 0): void
    {
        if ($depth > 5 || $value === null || $value === '') {
            return;
        }

        $references = DB::select(
            'SELECT TABLE_NAME AS related_table, COLUMN_NAME AS related_column
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME = ?
               AND REFERENCED_COLUMN_NAME = ?',
            [$table, $column]
        );

        foreach ($references as $reference) {
            $relatedTable = (string) ($reference->related_table ?? '');
            $relatedColumn = (string) ($reference->related_column ?? '');
            if (! preg_match('/^[A-Za-z0-9_]+$/', $relatedTable) || ! preg_match('/^[A-Za-z0-9_]+$/', $relatedColumn)) {
                continue;
            }
            if (! Schema::hasTable($relatedTable) || ! Schema::hasColumn($relatedTable, $relatedColumn)) {
                continue;
            }

            if (Schema::hasColumn($relatedTable, 'id')) {
                $childIds = DB::table($relatedTable)->where($relatedColumn, $value)->pluck('id');
                foreach ($childIds as $childId) {
                    $this->deleteReferencingRows($relatedTable, 'id', $childId, $depth + 1);
                }
            }

            DB::table($relatedTable)->where($relatedColumn, $value)->delete();
        }
    }

    private function deleteMatchingRows(string $table, string $column, mixed $value): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return;
        }
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->where($column, $value)->delete();
    }
}
