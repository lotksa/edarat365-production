<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Association extends Model
{
    protected $fillable = [
        'name', 'name_en', 'logo', 'registration_number', 'association_number',
        'city', 'address', 'phone', 'email', 'manager_name',
        'established_date', 'first_approval_date', 'expiry_date',
        'unified_number', 'establishment_number',
        'status', 'notes', 'management_model',
        'latitude', 'longitude', 'city_id', 'district_id',
        'manager_id', 'manager_start_date', 'manager_end_date',
        'manager_salary', 'has_commission', 'commission_type', 'commission_value',
        'has_national_address', 'address_type', 'address_short_code',
        'address_region', 'address_city_name', 'address_district', 'address_street',
        'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no',
    ];

    protected $casts = [
        'established_date'    => 'date',
        'first_approval_date' => 'date',
        'expiry_date'         => 'date',
        'manager_start_date'  => 'date',
        'manager_end_date'    => 'date',
        'manager_salary'      => 'decimal:2',
        'commission_value'    => 'decimal:2',
        'has_commission'      => 'boolean',
        'has_national_address' => 'boolean',
        'latitude'            => 'decimal:7',
        'longitude'           => 'decimal:7',
    ];

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(AssociationManager::class, 'manager_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
