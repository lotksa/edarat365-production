<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'name_ar', 'name_en',
        'description_ar', 'description_en',
        'color', 'is_system', 'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    public function syncPermissionKeys(array $keys): void
    {
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id')->all();
        $this->permissions()->sync($ids);
    }
}
