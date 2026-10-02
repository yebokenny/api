<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\MediaImage;
use App\Support\LegacyCodes;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    public function show(Request $request)
    {
        $author = $request->user()->author()->with('image')->first();

        return response()->json(['data' => $author?->toPublic(true)]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'profession' => ['required', 'string', 'max:160'],
            'about' => ['required', 'string', 'max:2000'],
            'hobbies' => ['nullable', 'string', 'max:240'],
            'isVisible' => ['required', 'boolean'],
        ]);

        $author = $this->authorFor($request);
        $author->fill([
            'DisplayName' => $data['name'],
            'JobTitle' => $data['profession'],
            'Description' => $data['about'],
            'Hobbies' => $data['hobbies'] ?? '',
            'IsVisible' => $data['isVisible'],
            'ModificationDate' => now(),
        ]);
        $author->save();
        $author->load('image');

        return response()->json(['data' => $author->toPublic(true)]);
    }

    public function photo(Request $request)
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        $image = \App\Support\MediaStore::store($request->file('photo'), 'author', $request->ip());

        $author = $this->authorFor($request);
        $author->AuthorInformationImageId = $image->getKey();
        $author->ModificationDate = now();
        $author->save();
        $author->load('image');

        return response()->json(['data' => $author->toPublic(true)]);
    }

    private function authorFor(Request $request): Author
    {
        $existing = $request->user()->author()->first();
        if ($existing) {
            return $existing;
        }

        return Author::create([
            'Id' => LegacyCodes::id(),
            'ApplicationUserId' => $request->user()->getKey(),
            'DisplayName' => $request->user()->name,
            'JobTitle' => '',
            'Hobbies' => '',
            'Description' => '',
            'IsVisible' => true,
            'CreationDate' => now(),
            'IsDeleted' => false,
            'TenantId' => LegacyCodes::TENANT,
        ]);
    }
}
