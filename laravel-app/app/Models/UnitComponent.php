<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitComponent extends Model
{
    protected $fillable = ['unit_id', 'component_key', 'quantity'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
