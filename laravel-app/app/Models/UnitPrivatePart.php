<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitPrivatePart extends Model
{
    protected $fillable = ['unit_id', 'name', 'area', 'sort_order'];

    protected $casts = [
        'area' => 'decimal:2',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
