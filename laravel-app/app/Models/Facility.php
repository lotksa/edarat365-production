<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    protected $fillable = [
        'association_id', 'property_id', 'name', 'description', 'facility_type',
        'is_active', 'is_bookable', 'capacity', 'hourly_rate',
        'location_detail', 'operating_hours_start', 'operating_hours_end',
        'images', 'rules',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_bookable' => 'boolean',
        'capacity' => 'integer',
        'hourly_rate' => 'decimal:2',
        'images' => 'array',
        'rules' => 'array',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
