<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Http\Request;

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

    public function destroy(Blog $blog)
    {
        $blog->delete();

        return response()->json(['data' => ['ok' => true]]);
    }
}
