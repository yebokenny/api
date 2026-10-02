<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaImage extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'image';

    protected $fillable = [
        'Id',
        'StrapiId',
        'Name',
        'Width',
        'Height',
        'ThumbnailUrl',
        'OriginalUrl',
        'MediumUrl',
        'SmallUrl',
        'Provider',
        'Mime',
        'Extension',
        'ClientIpAddress',
        'CreationDate',
        'IsDeleted',
        'TenantId',
    ];
}
