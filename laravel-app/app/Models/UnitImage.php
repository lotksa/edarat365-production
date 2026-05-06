<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitImage extends Model
{
    protected $fillable = ['unit_id', 'path', 'original_name', 'caption', 'sort_order'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
