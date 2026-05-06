<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssociationManager extends Model
{
    protected $fillable = [
        'full_name', 'national_id', 'phone', 'email', 'status',
    ];

    public function associations(): HasMany
    {
        return $this->hasMany(Association::class, 'manager_id');
    }
}
