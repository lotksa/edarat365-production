<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitOwner extends Pivot
{
    protected $table = 'unit_owners';

    public $incrementing = true;

    protected $fillable = ['unit_id', 'owner_id', 'ownership_ratio'];

    protected $casts = [
        'ownership_ratio' => 'decimal:2',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
