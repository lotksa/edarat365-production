<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    protected $fillable = [
        'property_number', 'name', 'type', 'association_id', 'address',
        'city', 'district', 'city_id', 'district_id',
        'total_units', 'total_floors', 'year_built',
        'status', 'notes',
        'plot_number', 'area', 'green_area', 'deed_number', 'deed_source',
        'total_elevators', 'latitude', 'longitude',
        'property_manager_id', 'build_date_type', 'build_date',
    ];

    protected function casts(): array
    {
        return [
            'build_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Property $p) {
            if (empty($p->property_number)) {
                $last = static::query()->orderByDesc('id')->value('id') ?? 0;
                $p->property_number = 'PROP-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function propertyManager(): BelongsTo
    {
        return $this->belongsTo(PropertyManager::class);
    }

    public function cityRelation(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function districtRelation(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(PropertyComponent::class);
    }

    public function utilityMeters(): HasMany
    {
        return $this->hasMany(UtilityMeter::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PropertyDocument::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function legalCases(): HasMany
    {
        return $this->hasMany(LegalCase::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }
}
