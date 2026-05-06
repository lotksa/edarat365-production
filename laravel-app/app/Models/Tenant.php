<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'full_name', 'national_id', 'phone',
        'email', 'nationality', 'status',
        'has_national_address', 'address_type', 'address_short_code',
        'address_region', 'address_city', 'address_district', 'address_street',
        'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no',
    ];

    protected $casts = [
        'has_national_address' => 'boolean',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
