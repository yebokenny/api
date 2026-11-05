<?php

namespace App\Models;

use App\Support\LegacyCodes;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Model;
use App\Support\MediaStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Business extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'businessprofile';

    protected $fillable = [
        'Id',
        'Name',
        'Description',
        'CategoryId',
        'PhoneNumber',
        'EmailAddress',
        'Website',
        'PhysicalAddress',
        'ApplicationUserId',
        'RecommendationCount',
        'YeboKennyScore',
        'ReviewStatus',
        'ReviewComments',
        'Rating',
        'Survey1',
        'Survey2',
        'Survey3',
        'Survey4',
        'Survey5',
        'CreationDate',
        'IsDeleted',
        'TenantId',
    ];

    protected function casts(): array
    {
        return [
            'YeboKennyScore' => 'float',
            'Rating' => 'float',
            'IsDeleted' => 'boolean',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return $this->where($field, $value)->first();
        }
        if (Str::isUuid((string) $value)) {
            return $this->where('Id', $value)->first();
        }

        $slug = Str::slug((string) $value);
        $like = '%'.str_replace('-', '%', $slug).'%';
        $matches = $this->where('IsDeleted', false)->where('Name', 'like', $like)->limit(15)->get();
        $exact = $matches->first(fn (self $business) => Str::slug((string) $business->Name) === $slug);

        return $exact ?: ($matches->count() === 1 ? $matches->first() : null);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ApplicationUserId', 'Id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'CategoryId', 'Id');
    }

    public function profileImage(): BelongsTo
    {
        return $this->belongsTo(MediaImage::class, 'BusinessProfileImageId', 'Id');
    }

    public function headerImage(): BelongsTo
    {
        return $this->belongsTo(MediaImage::class, 'BusinessHeaderImageId', 'Id');
    }

    public function gallery(): BelongsToMany
    {
        return $this->belongsToMany(MediaImage::class, 'businessprofileimage', 'BusinessProfilesId', 'ImagesId');
    }

    public function amenities(): HasMany
    {
        return $this->hasMany(BusinessAmenity::class, 'BusinessProfileId', 'Id')->where('IsDeleted', false);
    }

    public function products(): HasMany
    {
        return $this->hasMany(BusinessItem::class, 'BusinessProfileId', 'Id')
            ->where('IsDeleted', false)
            ->where('BusinessItemType', 1);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(BusinessHour::class, 'BusinessProfileId', 'Id')->where('IsDeleted', false);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(RecommendationInvite::class, 'BusinessProfileId', 'Id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class, 'BusinessProfileId', 'Id')->where('IsDeleted', false);
    }

    public function questions(): array
    {
        $own = array_values(array_filter([
            $this->Survey1, $this->Survey2, $this->Survey3, $this->Survey4, $this->Survey5,
        ]));
        if (count($own) > 0) {
            return $own;
        }

        return $this->category?->survey?->questions() ?? [];
    }

    public function toPublic(): array
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return [
            'id' => $this->getKey(),
            'name' => $this->Name,
            'slug' => Str::slug($this->Name) ?: 'business',
            'description' => RichText::clean($this->Description),
            'phone' => $this->PhoneNumber,
            'email' => $this->EmailAddress,
            'address' => $this->PhysicalAddress,
            'website' => $this->Website,
            'country' => $this->owner?->UserCountry ?: 'South Africa',
            'yeboScore' => (float) $this->YeboKennyScore,
            'recommendationCount' => (int) $this->RecommendationCount,
            'rating' => (float) $this->Rating,
            'reviewStatus' => LegacyCodes::reviewLabel((int) $this->ReviewStatus),
            'amenities' => ($this->relationLoaded('amenities') ? $this->amenities : $this->amenities()->get())
                ->map(fn (BusinessAmenity $row) => ['id' => $row->getKey(), 'name' => $row->Amenity])
                ->values()->all(),
            'products' => ($this->relationLoaded('products') ? $this->products : $this->products()->get())
                ->map(fn (BusinessItem $item) => $item->toPublic())
                ->values()->all(),
            'hours' => ($this->relationLoaded('hours') ? $this->hours : $this->hours()->get())
                ->map(fn (BusinessHour $hour) => [
                    'day' => $days[(int) $hour->DayOfWeek] ?? 'Day',
                    'open' => $hour->IsClosed ? null : substr((string) $hour->OpeningTime, 0, 5),
                    'close' => $hour->IsClosed ? null : substr((string) $hour->ClosingTime, 0, 5),
                    'closed' => (bool) $hour->IsClosed,
                ])->values()->all(),
            'surveyQuestions' => $this->questions(),
            'category' => $this->category ? [
                'id' => $this->category->getKey(),
                'name' => $this->category->CategoryName,
                'slug' => Str::slug($this->category->CategoryName) ?: 'category',
            ] : null,
            'owner' => $this->owner ? [
                'name' => $this->owner->name,
                'country' => $this->owner->UserCountry,
            ] : null,
            'author' => $this->visitorAuthor(),
            'profileImage' => MediaStore::payload($this->relationLoaded('profileImage') ? $this->profileImage : $this->profileImage()->first()),
            'headerImage' => MediaStore::payload($this->relationLoaded('headerImage') ? $this->headerImage : $this->headerImage()->first()),
            'gallery' => ($this->relationLoaded('gallery') ? $this->gallery : $this->gallery()->get())
                ->map(fn (MediaImage $image) => MediaStore::payload($image))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function visitorAuthor(): ?array
    {
        $author = $this->owner?->author;
        if (! $author || ! $author->IsVisible) {
            return null;
        }

        $payload = $author->toPublic();
        unset($payload['id'], $payload['isVisible'], $payload['shownToVisitors']);

        return $payload;
    }
}
