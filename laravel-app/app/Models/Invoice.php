<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number', 'invoice_type',
        'association_id', 'property_id', 'owner_id', 'unit_id', 'tenant_id',
        'amount', 'tax_amount', 'vat_rate', 'discount_amount', 'total_amount',
        'due_date', 'issue_date', 'payment_date',
        'status', 'payment_method',
        'description', 'line_items', 'notes',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'vat_rate'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'due_date'        => 'date',
        'issue_date'      => 'date',
        'payment_date'    => 'date',
        'line_items'      => 'array',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
