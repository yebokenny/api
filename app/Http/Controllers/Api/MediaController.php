<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessItem;
use App\Models\Category;
use App\Support\MediaStore;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MediaController extends Controller
{
    public function profile(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);

        return $this->replaceBusinessImage($request, $business, 'business-profile', 'BusinessProfileImageId');
    }

    public function header(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);

        return $this->replaceBusinessImage($request, $business, 'business-header', 'BusinessHeaderImageId');
    }

    public function gallery(Request $request, Business $business)
    {
        $this->authorizeOwner($request, $business);
        if (! $request->user()->hasFeature('ADD_BUSINESS_GALLERY') && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'A gallery is part of Silver, Gold, and Platinum.'], 422);
        }
        $image = $this->imageFrom($request, 'business-gallery');
        $exists = DB::table('businessprofileimage')
            ->where('BusinessProfilesId', $business->getKey())
            ->where('ImagesId', $image->getKey())
            ->exists();
        if (! $exists) {
            DB::table('businessprofileimage')->insert([
                'BusinessProfilesId' => $business->getKey(),
                'ImagesId' => $image->getKey(),
            ]);
        }

        return response()->json(['data' => $this->present($business)]);
    }

    public function removeGallery(Request $request, Business $business, string $image)
    {
        $this->authorizeOwner($request, $business);
        DB::table('businessprofileimage')
            ->where('BusinessProfilesId', $business->getKey())
            ->where('ImagesId', $image)
            ->delete();

        return response()->json(['data' => $this->present($business)]);
    }

    public function product(Request $request, Business $business, BusinessItem $product)
    {
        $this->authorizeOwner($request, $business);
        if ($product->BusinessProfileId !== $business->getKey()) {
            return response()->json(['message' => 'That product is not on this listing.'], 404);
        }
        $image = $this->imageFrom($request, 'product');
        $product->ImageUrl = $image->OriginalUrl;
        $product->ImageId = $image->getKey();
        $product->ModificationDate = now();
        $product->save();

        return response()->json(['data' => $product->toPublic()]);
    }

    public function categoryIcon(Request $request, Category $category)
    {
        return $this->replaceCategoryImage($request, $category, 'category-icon', 'CategoryImageId');
    }

    public function categoryHeader(Request $request, Category $category)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);
        $size = @getimagesize($request->file('file')->getRealPath()) ?: [0, 0];
        if ((int) $size[0] < 1920) {
            return response()->json(['message' => 'A category header needs to be at least 1920 pixels wide.'], 422);
        }

        return $this->saveCategoryImage($category, 'CategoryHeaderId', MediaStore::store($request->file('file'), 'category-header', $request->ip()));
    }

    public function legacyProfile(Request $request)
    {
        return $this->profile($request, $this->businessFrom($request));
    }

    public function legacyHeader(Request $request)
    {
        return $this->header($request, $this->businessFrom($request));
    }

    public function legacyGallery(Request $request)
    {
        return $this->gallery($request, $this->businessFrom($request));
    }

    public function legacyRemoveGallery(Request $request, string $businessId, string $imageId)
    {
        $business = Business::where('Id', $businessId)->where('IsDeleted', false)->first();
        if (! $business) {
            return response()->json(['message' => 'That listing was not found.'], 404);
        }

        return $this->removeGallery($request, $business, $imageId);
    }

    public function legacyCategoryIcon(Request $request)
    {
        return $this->categoryIcon($request, $this->categoryFrom($request));
    }

    public function legacyCategoryHeader(Request $request)
    {
        return $this->categoryHeader($request, $this->categoryFrom($request));
    }

    private function replaceBusinessImage(Request $request, Business $business, string $kind, string $column)
    {
        $image = $this->imageFrom($request, $kind);
        $business->{$column} = $image->getKey();
        $business->ModificationDate = now();
        $business->save();

        return response()->json(['data' => $this->present($business)]);
    }

    private function replaceCategoryImage(Request $request, Category $category, string $kind, string $column)
    {
        return $this->saveCategoryImage($category, $column, $this->imageFrom($request, $kind));
    }

    private function saveCategoryImage(Category $category, string $column, $image)
    {
        $category->{$column} = $image->getKey();
        $category->ModificationDate = now();
        $category->save();
        $category->load(['icon', 'header', 'survey']);

        return response()->json(['data' => $category->toPublic()]);
    }

    private function imageFrom(Request $request, string $kind)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        return MediaStore::store($request->file('file'), $kind, $request->ip());
    }

    private function present(Business $business): array
    {
        $business->load(['category.survey', 'category.icon', 'category.header', 'amenities', 'hours', 'products', 'profileImage', 'headerImage', 'gallery', 'owner.author.image']);

        return $business->toPublic();
    }

    private function businessFrom(Request $request): Business
    {
        $business = Business::where('Id', $request->input('businessProfileId'))->where('IsDeleted', false)->first();
        if (! $business) {
            throw new HttpResponseException(response()->json(['message' => 'That listing was not found.'], 404));
        }

        return $business;
    }

    private function categoryFrom(Request $request): Category
    {
        $category = Category::where('Id', $request->input('categoryId'))->where('IsDeleted', false)->first();
        if (! $category) {
            throw new HttpResponseException(response()->json(['message' => 'That category was not found.'], 404));
        }

        return $category;
    }

    private function authorizeOwner(Request $request, Business $business): void
    {
        if ($business->ApplicationUserId !== $request->user()->getKey() && $request->user()->role !== 'admin') {
            throw new HttpResponseException(response()->json(['message' => 'This listing belongs to another account.'], 403));
        }
    }
}
