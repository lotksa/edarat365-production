<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRequest extends Model
{
    protected $fillable = [
        'association_id', 'property_id', 'owner_id', 'unit_id',
        'title', 'type', 'category', 'description', 'location',
        'priority', 'status',
        'assigned_to', 'assigned_phone',
        'estimated_cost', 'actual_cost',
        'scheduled_date', 'completed_date',
        'resolution_notes', 'images', 'rating',
    ];

    protected $casts = [
        'images' => 'array',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'scheduled_date' => 'date',
        'completed_date' => 'date',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
