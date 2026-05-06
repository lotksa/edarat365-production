<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyManager extends Model
{
    protected $fillable = [
        'full_name',
        'national_id',
        'phone',
        'email',
        'status',
    ];

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
