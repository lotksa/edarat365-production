<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseUpdate extends Model
{
    protected $fillable = [
        'legal_case_id', 'title', 'verdict_status', 'reminder_date',
        'details', 'documents', 'created_by',
    ];

    protected $casts = [
        'documents' => 'array',
        'reminder_date' => 'datetime',
    ];

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
