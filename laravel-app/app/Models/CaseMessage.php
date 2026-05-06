<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseMessage extends Model
{
    protected $fillable = [
        'legal_case_id', 'sender_id', 'reply_to_id',
        'content', 'type', 'attachments', 'read_by', 'is_pinned',
    ];

    protected $casts = [
        'attachments' => 'array',
        'read_by'     => 'array',
        'is_pinned'   => 'boolean',
    ];

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }
}
