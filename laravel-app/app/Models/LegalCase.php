<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalCase extends Model
{
    protected $fillable = [
        'case_number', 'title', 'case_type', 'status',
        'association_id', 'property_id', 'owner_id', 'unit_id',
        'court_name', 'court_type', 'plaintiff', 'defendant', 'lawyer_name',
        'filing_date', 'hearing_date', 'priority',
        'description', 'verdict', 'amount', 'notes', 'documents',
    ];

    protected $casts = [
        'filing_date'  => 'date',
        'hearing_date' => 'date',
        'amount'       => 'decimal:2',
        'documents'    => 'array',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(CaseUpdate::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(CasePermission::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CaseMessage::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(CaseReminder::class);
    }
}
