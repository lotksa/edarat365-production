<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UtilityMeter extends Model
{
    protected $fillable = [
        'property_id',
        'meter_type',
        'meter_number',
        'account_number',
        'account_type',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
