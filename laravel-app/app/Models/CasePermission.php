<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasePermission extends Model
{
    protected $fillable = [
        'legal_case_id', 'user_id', 'representative_id',
        'role', 'permissions', 'granted_by',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(LegalRepresentative::class, 'representative_id');
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
