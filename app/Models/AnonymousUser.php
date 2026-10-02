<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnonymousUser extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'anonymoususer';

    protected $fillable = [
        'Id', 'EmailAddress', 'IpAddress', 'BrowserName', 'CreationDate', 'IsDeleted', 'TenantId',
    ];
}
