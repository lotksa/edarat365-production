<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    protected $fillable = ['property_id', 'request_type', 'status', 'requested_by', 'notes'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
