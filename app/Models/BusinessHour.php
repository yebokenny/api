<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessHour extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'businesshour';

    protected $fillable = [
        'Id', 'DayOfWeek', 'BusinessProfileId', 'OpeningTime', 'ClosingTime', 'IsClosed', 'CreationDate', 'ModificationDate', 'IsDeleted', 'TenantId',
    ];
}
