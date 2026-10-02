<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'users';

    public $timestamps = false;

    protected $fillable = [
        'Id',
        'FirstName',
        'LastName',
        'UserName',
        'NormalizedUserName',
        'Email',
        'NormalizedEmail',
        'EmailConfirmed',
        'PasswordHash',
        'SecurityStamp',
        'ConcurrencyStamp',
        'PhoneNumber',
        'PhoneNumberConfirmed',
        'TwoFactorEnabled',
        'LockoutEnabled',
        'AccessFailedCount',
        'RefreshTokenExpiryTime',
        'IsLocked',
        'UserCountry',
        'UserType',
        'PlanId',
        'CustomerCode',
        'SubscriptionStatus',
        'AllowEmailNotifications',
        'ProviderType',
        'RequestedAccountDeletion',
    ];

    protected function casts(): array
    {
        return [
            'IsLocked' => 'boolean',
            'EmailConfirmed' => 'boolean',
            'AllowEmailNotifications' => 'boolean',
            'RequestedAccountDeletion' => 'boolean',
        ];
    }

    protected $hidden = [
        'PasswordHash',
        'SecurityStamp',
        'ConcurrencyStamp',
        'RefreshToken',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'PlanId', 'Id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'UserId', 'RoleId', 'Id', 'Id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ApiToken::class, 'user_id', 'Id');
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'ApplicationUserId', 'Id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'ApplicationUserId', 'Id');
    }

    public function author(): HasOne
    {
        return $this->hasOne(Author::class, 'ApplicationUserId', 'Id')->where('IsDeleted', false);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'ApplicationUserId', 'Id')->where('IsDeleted', false);
    }

    public function getNameAttribute(): string
    {
        $name = trim(($this->FirstName ?? '').' '.($this->LastName ?? ''));

        return $name !== '' ? $name : (string) $this->Email;
    }

    public function getRoleAttribute(): string
    {
        $names = $this->relationLoaded('roles') ? $this->roles->pluck('Name') : $this->roles()->pluck('Name');
        if ($names->contains('Admin') || (int) $this->UserType === 173127) {
            return 'admin';
        }

        return 'owner';
    }

    public function issueToken(): string
    {
        $plain = bin2hex(random_bytes(32));
        $this->tokens()->create([
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addDays(7),
        ]);

        return $plain;
    }

    public function hasFeature(string $code): bool
    {
        $features = $this->plan?->featureCodes() ?? [];

        return in_array($code, $features, true);
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->getKey(),
            'firstName' => $this->FirstName,
            'lastName' => $this->LastName,
            'name' => $this->name,
            'email' => $this->Email,
            'phone' => $this->PhoneNumber,
            'country' => $this->UserCountry ?: 'South Africa',
            'role' => $this->role,
            'subscriptionStatus' => \App\Support\LegacyCodes::subscriptionLabel((int) $this->SubscriptionStatus),
            'plan' => $this->plan?->toPublic(),
            'features' => $this->plan?->featureCodes() ?? [],
            'locked' => (bool) $this->IsLocked,
            'emailConfirmed' => (bool) $this->EmailConfirmed,
            'allowEmail' => (bool) $this->AllowEmailNotifications,
            'deletionRequested' => (bool) $this->RequestedAccountDeletion,
        ];
    }
}
