<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'contract_number', 'contract_name', 'contract_nature', 'contract_type',
        'contract_date', 'venue', 'venue_address', 'venue_city',
        'party1_type', 'party1_name', 'party1_national_id', 'party1_phone', 'party1_email', 'party1_address',
        'party2_type', 'party2_name', 'party2_national_id', 'party2_phone', 'party2_email', 'party2_address',
        'preamble', 'contract_clauses',
        'property_id', 'owner_id', 'unit_id', 'tenant_id',
        'tenant_name', 'start_date', 'end_date',
        'payment_type', 'contract_period', 'rental_amount',
        'status', 'notes',
        'utilities_responsibility', 'insurance_required', 'maintenance_responsibility',
        'ejar_reference_id', 'ejar_status', 'ejar_synced_at',
    ];

    protected $casts = [
        'rental_amount'      => 'decimal:2',
        'contract_date'      => 'date',
        'start_date'         => 'date',
        'end_date'           => 'date',
        'insurance_required' => 'boolean',
        'contract_clauses'   => 'array',
        'ejar_synced_at'     => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
