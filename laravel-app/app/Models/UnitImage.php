<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "unit attachment" – the legacy table name `unit_images` is kept for
 * compatibility with existing data, but every row may now hold either an
 * image or a document (PDF, Word, Excel, …). The `kind` column ('image' |
 * 'document') drives the UI's render strategy.
 */
class UnitImage extends Model
{
    protected $fillable = [
        'unit_id',
        'path',
        'original_name',
        'caption',
        'sort_order',
        'kind',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Best-effort kind resolution for legacy rows that pre-date the column.
     * Falls back to inspecting the path extension when the DB value is null.
     */
    public function getResolvedKindAttribute(): string
    {
        if (! empty($this->attributes['kind'])) {
            return $this->attributes['kind'];
        }
        $ext = strtolower(pathinfo($this->path ?? '', PATHINFO_EXTENSION));
        $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'heic', 'heif'];
        return in_array($ext, $imageExts, true) ? 'image' : 'document';
    }
}
