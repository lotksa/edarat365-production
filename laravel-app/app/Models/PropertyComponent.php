<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyComponent extends Model
{
    protected $fillable = ['property_id', 'component_key', 'quantity'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
