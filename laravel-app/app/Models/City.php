<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = ['name_ar', 'name_en', 'latitude', 'longitude', 'status'];

    protected $casts = [
        'latitude'  => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }
}
