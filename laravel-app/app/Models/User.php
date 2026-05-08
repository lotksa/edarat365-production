<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// SECURITY: only the explicit `$fillable`/`$hidden` arrays below are the
// source of truth for mass-assignment policy. The PHP 8 attribute variants
// were removed because they were duplicated and risked drifting away from
// the array properties (e.g. forgetting `password_changed_at`).
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'role', 'role_id',
        'is_active', 'last_login_at', 'avatar_url', 'password',
        'password_changed_at',
        // Note: failed_login_attempts, locked_until, last_failed_login_at
        // and last_login_ip are intentionally NOT fillable — they must be
        // updated only by AuthController via direct assignment so they cannot
        // be modified through mass-assignment from API requests.
    ];

    protected $hidden = [
        'password', 'remember_token',
        'failed_login_attempts', 'locked_until', 'last_failed_login_at',
        'last_login_ip', 'password_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'last_login_at'         => 'datetime',
            'last_failed_login_at'  => 'datetime',
            'locked_until'          => 'datetime',
            'password_changed_at'   => 'datetime',
            'is_active'             => 'boolean',
            'failed_login_attempts' => 'integer',
            'password'              => 'hashed',
        ];
    }

    /**
     * Note: relationship is named userRole to avoid collision with the existing
     * legacy `role` string column on users. Use $user->userRole->key for the slug.
     */
    public function userRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Backwards-compat accessor: when a Role is loaded via with('userRole'),
     * exposing it through $user->role_object for clarity in API responses.
     */
    public function getRoleObjectAttribute(): ?Role
    {
        return $this->relationLoaded('userRole') ? $this->userRole : null;
    }

    public function permissionKeys(): array
    {
        $role = $this->relationLoaded('userRole') ? $this->userRole : $this->userRole()->with('permissions')->first();
        if (! $role instanceof Role) {
            return [];
        }
        if ($role->key === 'super_admin') {
            return \App\Models\Permission::query()->pluck('key')->all();
        }
        $role->loadMissing('permissions');
        return $role->permissions->pluck('key')->all();
    }

    public function hasPermission(string $key): bool
    {
        $keys = $this->permissionKeys();
        return in_array($key, $keys, true);
    }

    public function isSuperAdmin(): bool
    {
        $role = $this->relationLoaded('userRole') ? $this->userRole : $this->userRole()->first();
        return $role instanceof Role && $role->key === 'super_admin';
    }
}
