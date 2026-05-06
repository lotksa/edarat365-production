<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseReminder extends Model
{
    protected $fillable = [
        'legal_case_id', 'created_by', 'title',
        'description', 'remind_at', 'status',
    ];

    protected $casts = [
        'remind_at' => 'datetime',
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
