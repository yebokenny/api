<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessAmenity extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'businessamenity';

    protected $fillable = [
        'Id', 'Amenity', 'BusinessProfileId', 'CreationDate', 'IsDeleted', 'TenantId',
    ];
}
