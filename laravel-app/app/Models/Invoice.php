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
        // ZATCA lifecycle (see 2026_05_10_200000 migration)
        'issued_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'original_invoice_id', 'replacement_invoice_id',
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
        'issued_at'       => 'datetime',
        'cancelled_at'    => 'datetime',
    ];

    protected $appends = ['is_locked'];

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

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function replacementInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'replacement_invoice_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * ZATCA lock rule: an invoice that has been ISSUED (status != 'draft')
     * cannot be edited. Cancelled invoices are also locked. Drafts remain
     * fully editable (pre-issuance state).
     */
    public function getIsLockedAttribute(): bool
    {
        if ($this->cancelled_at) return true;
        $status = (string) ($this->attributes['status'] ?? '');
        return $status !== '' && $status !== 'draft';
    }
}
