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
        'bedrooms', 'bathrooms', 'furnished', 'percentage',
        'status', 'monthly_rent', 'notes',
    ];

    protected $casts = [
        'percentage' => 'decimal:4',
        'area' => 'decimal:2',
        'monthly_rent' => 'decimal:2',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(UnitComponent::class);
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
