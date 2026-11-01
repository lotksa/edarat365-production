<?php

namespace App\Models;

use App\Models\Concerns\DisplaysBuildingTerminology;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use DisplaysBuildingTerminology;
    use HasFactory;

    protected array $buildingTerminologyFields = ['group_name_ar', 'group_name_en', 'name_ar', 'name_en'];

    protected $fillable = [
        'key', 'group_key', 'group_name_ar', 'group_name_en',
        'name_ar', 'name_en',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
