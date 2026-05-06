<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyDocument extends Model
{
    protected $fillable = [
        'property_id',
        'doc_name',
        'doc_type',
        'file_path',
        'mime_type',
        'file_extension',
        'file_size',
        'uploaded_by',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
