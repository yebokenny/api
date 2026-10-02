<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Plan extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'plan';

    protected $fillable = [
        'Name',
        'Description',
        'Price',
        'Currency',
        'IsEnabled',
    ];

    protected function casts(): array
    {
        return [
            'IsFree' => 'boolean',
            'IsEnabled' => 'boolean',
            'Price' => 'float',
        ];
    }

    public function featureRows(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'featureplan', 'PlansId', 'FeaturesId', 'Id', 'Id');
    }

    public function featureCodes(): array
    {
        return $this->featureRows->pluck('NormalizedName')->filter()->values()->all();
    }

    public function toPublic(): array
    {
        $currency = (int) $this->Currency === 1 ? 'NGN' : 'ZAR';
        $price = (int) round((float) $this->Price);
        $interval = match ((int) $this->PeriodType) {
            3 => 'month',
            5 => 'year',
            6 => 'quarter',
            default => 'once',
        };

        return [
            'id' => $this->getKey(),
            'name' => $this->Name,
            'slug' => Str::slug($this->Name) ?: 'plan',
            'description' => $this->Description,
            'priceZar' => $currency === 'ZAR' ? $price : 0,
            'priceNgn' => $currency === 'NGN' ? $price : 0,
            'currency' => $currency,
            'interval' => $interval,
            'isFree' => (bool) $this->IsFree,
            'isEnabled' => (bool) $this->IsEnabled,
            'features' => $this->featureCodes(),
        ];
    }
}
