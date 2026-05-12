<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = [
        'property_id', 'unit_code', 'unit_number', 'unit_type', 'description',
        'building_name', 'floor_number', 'area', 'deed_number', 'deed_source',
        'site_city', 'site_district', 'site_plan_number', 'site_plot_number',
        'building_permit_number', 'building_permit_date', 'street_name', 'street_width',
        'land_area', 'real_estate_number', 'built_up_area',
        'bedrooms', 'bathrooms', 'furnished', 'percentage',
        'status', 'monthly_rent', 'notes',
    ];

    protected $casts = [
        'percentage' => 'decimal:4',
        'area' => 'decimal:2',
        'street_width' => 'decimal:2',
        'land_area' => 'decimal:2',
        'built_up_area' => 'decimal:2',
        'building_permit_date' => 'date',
        'monthly_rent' => 'decimal:2',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(UnitComponent::class);
    }

    public function privateParts(): HasMany
    {
        return $this->hasMany(UnitPrivatePart::class)->orderBy('sort_order')->orderBy('id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(Owner::class, 'unit_owners')
            ->using(UnitOwner::class)
            ->withPivot('ownership_ratio')
            ->withTimestamps();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(UnitImage::class)->orderBy('sort_order');
    }
}
