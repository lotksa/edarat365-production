<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Setting;
use App\Models\Unit;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    /**
     * Statuses considered "draft" — only drafts may be freely edited or
     * deleted. Anything else is treated as ISSUED for ZATCA purposes.
     */
    private const DRAFT_STATUSES = ['draft'];
    private const PAYMENT_UPDATE_FIELDS = ['status', 'payment_date', 'payment_method'];

    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['association', 'property', 'owner', 'unit', 'tenant']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('owner', fn ($oq) => $oq->where('full_name', 'like', "%{$search}%"));
            });
        }

        if ($v = $request->query('status'))           $query->where('status', $v);
        if ($v = $request->query('invoice_type'))     $query->where('invoice_type', $v);
        if ($v = $request->query('association_id')) {
            $query->where(function ($q) use ($v) {
                $q->where('association_id', $v)
                  ->orWhereHas('property', fn ($pq) => $pq->where('association_id', $v))
                  ->orWhereHas('unit.property', fn ($pq) => $pq->where('association_id', $v));
            });
        }
        if ($v = $request->query('property_id')) {
            $query->where(function ($q) use ($v) {
                $q->where('property_id', $v)
                  ->orWhereHas('unit', fn ($uq) => $uq->where('property_id', $v));
            });
        }
        if ($v = $request->query('owner_id')) {
            $query->where(function ($q) use ($v) {
                $q->where('owner_id', $v)
                  ->orWhereHas('unit.owners', fn ($oq) => $oq->where('owners.id', $v))
                  ->orWhereHas('property.owners', fn ($oq) => $oq->where('owners.id', $v));
            });
        }
        if ($v = $request->query('unit_id'))          $query->where('unit_id', $v);
        if ($v = $request->query('tenant_id'))        $query->where('tenant_id', $v);

        $perPage = (int) $request->query('per_page', 15);
        $records = $query->latest('id')->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total'     => Invoice::count(),
            'pending'   => Invoice::whereIn('status', ['pending', 'unpaid'])->count(),
            'paid'      => Invoice::where('status', 'paid')->count(),
            'overdue'   => Invoice::where('status', 'overdue')->count(),
            'partial'   => Invoice::where('status', 'partial')->count(),
            'cancelled' => Invoice::whereNotNull('cancelled_at')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with([
            'association', 'property', 'owner', 'unit', 'tenant',
            'originalInvoice:id,invoice_number,status,total_amount',
            'replacementInvoice:id,invoice_number,status,total_amount',
            'canceller:id,name',
        ])->findOrFail($id);
        return response()->json(['data' => $invoice]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateInvoicePayload($request);
        $this->normalizeInvoiceScope($data);
        $this->assertOwnerBelongsToInvoiceAssociation($data);

        // Default status: 'unpaid' unless caller explicitly sent 'draft'.
        $data['status'] = $data['status'] ?? 'unpaid';
        $data['issue_date'] = $data['issue_date'] ?? now()->toDateString();
        $this->normalizePaymentState($data);

        // ZATCA: anything that is not a draft is considered ISSUED, so we
        // capture the issuance moment to seal the audit trail.
        if (!in_array($data['status'], self::DRAFT_STATUSES, true)) {
            $data['issued_at'] = now();
        }

        $data['invoice_number'] = $this->nextInvoiceNumber();
        $this->recalculateTotals($data);

        $invoice = Invoice::create($data);

        $verb = ($data['status'] === 'draft') ? 'created' : 'issued';
        $verbAr = ($data['status'] === 'draft') ? 'تم حفظ مسودة فاتورة' : 'تم إصدار فاتورة';
        ActivityLog::record('invoice', $invoice->id, $verb, $verbAr . ' — ' . $invoice->invoice_number, null, [
            'invoice_number' => $invoice->invoice_number,
            'status'         => $invoice->status,
            'total_amount'   => (float) $invoice->total_amount,
        ]);

        // Notify admins (and the owner if linked) — drafts are not announced.
        if ($invoice->status !== 'draft') {
            Notifier::dispatch('invoice.created', [
                'subject'  => $invoice,
                'owner_id' => $invoice->owner_id,
                'data'     => [
                    'number'   => $invoice->invoice_number,
                    'amount'   => number_format((float) $invoice->total_amount, 2),
                    'status'   => $invoice->status,
                    'due_date' => optional($invoice->due_date)->format('Y-m-d'),
                ],
            ]);
        }

        return response()->json([
            'message' => 'تم إنشاء الفاتورة بنجاح',
            'data'    => $invoice->load(['association', 'property', 'owner', 'unit', 'tenant']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $data = $this->validateInvoicePayload($request);

        // ZATCA: only DRAFT invoices may be edited. After issuance, the
        // legal flow is cancel + reissue, never in-place content changes.
        // Payment status is operational metadata, so a narrow update is
        // allowed for marking an issued invoice paid/unpaid without touching
        // any legal invoice content.
        if ($invoice->is_locked) {
            if ($this->isPaymentOnlyUpdate($request) && !$invoice->cancelled_at && $invoice->status !== 'cancelled') {
                return $this->updatePaymentOnly($invoice, $data);
            }

            return response()->json([
                'message' => 'لا يمكن تعديل فاتورة مُصدرة أو ملغاة. يجب إلغاء الفاتورة الحالية وإصدار فاتورة جديدة.',
                'reason'  => 'zatca_locked',
                'is_locked' => true,
            ], 422);
        }

        $this->normalizeInvoiceScope($data, $invoice);
        $this->assertOwnerBelongsToInvoiceAssociation($data);

        $oldStatus = $invoice->status;
        $newStatus = $data['status'] ?? $oldStatus;
        $this->normalizePaymentState($data, $invoice);

        // If transitioning OUT of draft into an issued status, capture the
        // issuance moment exactly once.
        if (in_array($oldStatus, self::DRAFT_STATUSES, true)
            && !in_array($newStatus, self::DRAFT_STATUSES, true)
            && empty($invoice->issued_at)) {
            $data['issued_at'] = now();
        }

        $this->recalculateTotals($data, $invoice);
        $invoice->update($data);

        ActivityLog::record('invoice', $invoice->id, 'updated', 'تم تحديث مسودة فاتورة — ' . $invoice->invoice_number);

        // Status transitions worth announcing: paid, overdue.
        if ($newStatus !== $oldStatus && in_array($newStatus, ['paid', 'overdue'], true)) {
            Notifier::dispatch("invoice.{$newStatus}", [
                'subject'  => $invoice,
                'owner_id' => $invoice->owner_id,
                'data'     => [
                    'number'   => $invoice->invoice_number,
                    'amount'   => number_format((float) $invoice->total_amount, 2),
                    'status'   => $newStatus,
                    'due_date' => optional($invoice->due_date)->format('Y-m-d'),
                ],
            ]);
        }

        return response()->json([
            'message' => 'تم تحديث الفاتورة بنجاح',
            'data'    => $invoice->fresh()->load(['association', 'property', 'owner', 'unit', 'tenant']),
        ]);
    }

    /**
     * ZATCA-compliant cancellation. The invoice row is preserved (audit
     * trail), the row is sealed against further edits, and a reason is
     * required. A subsequent reissue() can then be called to issue a new
     * invoice that supersedes this one.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->cancelled_at) {
            return response()->json([
                'message' => 'الفاتورة ملغاة مسبقاً',
                'data'    => $invoice->fresh(),
            ], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'سبب الإلغاء مطلوب',
            'reason.min'      => 'يرجى توضيح سبب الإلغاء (3 أحرف على الأقل)',
        ]);

        $invoice->update([
            'cancelled_at'        => now(),
            'cancelled_by'        => auth()->id() ?? null,
            'cancellation_reason' => $data['reason'],
            'status'              => 'cancelled',
        ]);

        Notifier::dispatch('invoice.cancelled', [
            'subject'  => $invoice,
            'owner_id' => $invoice->owner_id,
            'data'     => [
                'number' => $invoice->invoice_number,
                'reason' => $data['reason'],
            ],
        ]);

        ActivityLog::record('invoice', $invoice->id, 'cancelled',
            'تم إلغاء فاتورة — ' . $invoice->invoice_number,
            null,
            ['reason' => $data['reason']]
        );

        return response()->json([
            'message' => 'تم إلغاء الفاتورة',
            'data'    => $invoice->fresh()->load(['association', 'property', 'owner', 'unit', 'tenant']),
        ]);
    }

    /**
     * Re-issue: clones the invoice into a NEW row with a fresh number, links
     * the two together (original_invoice_id ↔ replacement_invoice_id) and
     * returns the new draft so the user can adjust + issue it. This is the
     * ZATCA-compliant alternative to "edit after issue".
     */
    public function reissue(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        // Encourage the cancel-first flow but allow reissuing a paid/issued
        // invoice directly when the user already typed a reason for cancel
        // (e.g. when reissuing-as-correction in a single click).
        $reasonInput = (string) $request->input('reason', '');

        return DB::transaction(function () use ($invoice, $reasonInput) {
            // 1. If the source invoice is still issued (not yet cancelled),
            //    cancel it first as part of the reissue flow.
            if (!$invoice->cancelled_at) {
                $reason = $reasonInput !== '' ? $reasonInput : 'إعادة إصدار الفاتورة';
                $invoice->update([
                    'cancelled_at'        => now(),
                    'cancelled_by'        => auth()->id() ?? null,
                    'cancellation_reason' => $reason,
                    'status'              => 'cancelled',
                ]);
                ActivityLog::record('invoice', $invoice->id, 'cancelled',
                    'تم إلغاء فاتورة — ' . $invoice->invoice_number . ' (إعادة إصدار)',
                    null, ['reason' => $reason]
                );
            }

            // 2. Clone the invoice as a NEW draft.
            $copy = $invoice->replicate([
                'invoice_number',
                'cancelled_at', 'cancelled_by', 'cancellation_reason',
                'replacement_invoice_id', 'issued_at',
                'created_at', 'updated_at',
            ]);
            $copy->invoice_number = $this->nextInvoiceNumber();
            $copy->status = 'draft';
            $copy->original_invoice_id = $invoice->id;
            $copy->issue_date = now()->toDateString();
            $copy->payment_date = null;
            $copy->save();

            // 3. Two-way link.
            $invoice->update(['replacement_invoice_id' => $copy->id]);

            ActivityLog::record('invoice', $copy->id, 'reissued',
                'تم إصدار فاتورة بديلة — ' . $copy->invoice_number . ' (تستبدل ' . $invoice->invoice_number . ')',
                null,
                ['original_invoice_id' => $invoice->id, 'original_number' => $invoice->invoice_number]
            );

            return response()->json([
                'message' => 'تم إنشاء فاتورة بديلة كمسودة',
                'data'    => $copy->fresh()->load(['association', 'property', 'owner', 'unit', 'tenant', 'originalInvoice']),
            ], 201);
        });
    }

    public function destroy(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        // ZATCA: never destroy issued invoices. They may be cancelled, which
        // preserves the audit trail. Only drafts may be physically removed.
        if (!in_array($invoice->status, self::DRAFT_STATUSES, true) || $invoice->issued_at) {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة مُصدرة. استخدم زر "إلغاء الفاتورة" بدلاً من ذلك.',
                'reason'  => 'zatca_locked',
            ], 422);
        }

        ActivityLog::record('invoice', $invoice->id, 'deleted', 'تم حذف مسودة فاتورة — ' . $invoice->invoice_number);
        $invoice->delete();
        return response()->json(['message' => 'تم حذف المسودة بنجاح']);
    }

    /* ──────────── helpers ──────────── */

    private function validateInvoicePayload(Request $request): array
    {
        return $request->validate([
            'invoice_type'    => ['nullable', 'string', 'max:100'],
            'association_id'  => ['nullable', 'exists:associations,id'],
            'property_id'     => ['nullable', 'exists:properties,id'],
            'owner_id'        => ['nullable', 'exists:owners,id'],
            'unit_id'         => ['nullable', 'exists:units,id'],
            'tenant_id'       => ['nullable', 'exists:tenants,id'],
            'amount'          => ['nullable', 'numeric', 'min:0'],
            'tax_amount'      => ['nullable', 'numeric', 'min:0'],
            'vat_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount'    => ['nullable', 'numeric', 'min:0'],
            'due_date'        => ['nullable', 'date'],
            'issue_date'      => ['nullable', 'date'],
            'payment_date'    => ['nullable', 'date'],
            'payment_method'  => ['nullable', 'string', 'max:100'],
            'description'    => ['nullable', 'string'],
            'line_items'      => ['nullable', 'array'],
            'notes'           => ['nullable', 'string'],
            'status'          => ['nullable', 'string', 'max:50'],
        ]);
    }

    /**
     * Generates the next sequential invoice number (INV-YYYY-#####).
     * Race-safe enough for the single-instance cPanel deploy: based on the
     * current MAX(id) + 1, scoped to the current year for readability.
     */
    private function nextInvoiceNumber(): string
    {
        $next = ((int) (Invoice::max('id') ?? 0)) + 1;
        return 'INV-' . date('Y') . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function recalculateTotals(array &$data, ?Invoice $existing = null): void
    {
        $subtotal = $this->lineItemsSubtotal($data['line_items'] ?? null);
        if ($subtotal === null) {
            $subtotal = (float) ($data['amount'] ?? $existing->amount ?? 0);
        }

        $tax = $this->invoiceTaxSettings();
        $vatRate = $tax['vat_enabled']
            ? (float) ($data['vat_rate'] ?? $existing->vat_rate ?? $tax['vat_rate'])
            : 0.0;
        $discount = (float) ($data['discount_amount'] ?? $existing->discount_amount ?? 0);
        $taxAmt = $subtotal * ($vatRate / 100);
        $data['amount']       = round($subtotal, 2);
        $data['vat_rate']     = round($vatRate, 2);
        $data['tax_amount']   = round($taxAmt, 2);
        $data['total_amount'] = max(0, round($subtotal + $taxAmt - $discount, 2));
    }

    private function lineItemsSubtotal(mixed $lineItems): ?float
    {
        if (!is_array($lineItems)) {
            return null;
        }

        $subtotal = 0.0;
        foreach ($lineItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists('total_price', $row)) {
                $subtotal += (float) $row['total_price'];
                continue;
            }
            $subtotal += ((float) ($row['quantity'] ?? 0)) * ((float) ($row['unit_price'] ?? 0));
        }

        return round($subtotal, 2);
    }

    private function invoiceTaxSettings(): array
    {
        $settings = Setting::getByKey('invoice_settings', []);
        $tax = $settings['tax'] ?? [];

        return [
            'vat_enabled' => filter_var($tax['vat_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'vat_rate'    => (float) ($tax['vat_rate'] ?? 15),
        ];
    }

    private function normalizeInvoiceScope(array &$data, ?Invoice $existing = null): void
    {
        $unitId = $data['unit_id'] ?? $existing?->unit_id;
        $unit = $unitId ? Unit::with(['property.owners', 'owners'])->find($unitId) : null;

        if ($unit) {
            $data['property_id'] = $unit->property_id ?: ($data['property_id'] ?? $existing?->property_id);
            if ($unit->property?->association_id) {
                $data['association_id'] = $unit->property->association_id;
            }
            if (empty($data['owner_id'])) {
                $unitOwners = $unit->owners;
                if ($unitOwners->count() === 1) {
                    $data['owner_id'] = $unitOwners->first()->id;
                } elseif ($unit->getAttribute('owner_id')) {
                    $data['owner_id'] = $unit->getAttribute('owner_id');
                }
            }
        }

        $propertyId = $data['property_id'] ?? $existing?->property_id;
        $property = $propertyId ? Property::with('owners')->find($propertyId) : null;

        if ($property) {
            if ($property->association_id) {
                $data['association_id'] = $property->association_id;
            }
            if (empty($data['owner_id'])) {
                $propertyOwners = $property->owners;
                if ($propertyOwners->count() === 1) {
                    $data['owner_id'] = $propertyOwners->first()->id;
                }
            }
        }
    }

    private function assertOwnerBelongsToInvoiceAssociation(array $data): void
    {
        $associationId = $data['association_id'] ?? null;
        $ownerId = $data['owner_id'] ?? null;

        if (!$associationId || !$ownerId) {
            return;
        }

        $belongs = Owner::query()
            ->whereKey($ownerId)
            ->where(function ($q) use ($associationId) {
                $q->whereHas('properties', fn ($pq) => $pq->where('association_id', $associationId))
                  ->orWhereHas('units.property', fn ($uq) => $uq->where('association_id', $associationId));
            })
            ->exists();

        if (!$belongs) {
            throw ValidationException::withMessages([
                'owner_id' => ['المالك المحدد غير مسجل ضمن هذه الجمعية.'],
            ]);
        }
    }

    private function isPaymentOnlyUpdate(Request $request): bool
    {
        $keys = array_keys($request->all());
        if (!$keys) {
            return false;
        }

        return count(array_diff($keys, self::PAYMENT_UPDATE_FIELDS)) === 0;
    }

    private function updatePaymentOnly(Invoice $invoice, array $data): JsonResponse
    {
        $oldStatus = $invoice->status;
        $status = $data['status'] ?? $oldStatus;

        if (in_array($status, self::DRAFT_STATUSES, true) || $status === 'cancelled') {
            return response()->json([
                'message' => 'حالة السداد غير صحيحة لهذه الفاتورة.',
            ], 422);
        }

        $updates = [];
        if (array_key_exists('status', $data)) {
            $updates['status'] = $status;
        }
        if (array_key_exists('payment_date', $data)) {
            $updates['payment_date'] = $data['payment_date'];
        }
        if (array_key_exists('payment_method', $data)) {
            $updates['payment_method'] = $data['payment_method'];
        }

        $this->normalizePaymentState($updates, $invoice);
        $invoice->update($updates);

        ActivityLog::record('invoice', $invoice->id, 'payment_status_updated',
            'تم تحديث حالة سداد الفاتورة — ' . $invoice->invoice_number,
            ['status' => $oldStatus],
            ['status' => $invoice->fresh()->status, 'payment_date' => $invoice->fresh()->payment_date]
        );

        if (($updates['status'] ?? $oldStatus) !== $oldStatus && ($updates['status'] ?? null) === 'paid') {
            Notifier::dispatch('invoice.paid', [
                'subject'  => $invoice,
                'owner_id' => $invoice->owner_id,
                'data'     => [
                    'number' => $invoice->invoice_number,
                    'amount' => number_format((float) $invoice->total_amount, 2),
                    'status' => 'paid',
                ],
            ]);
        }

        return response()->json([
            'message' => 'تم تحديث حالة السداد بنجاح',
            'data'    => $invoice->fresh()->load(['association', 'property', 'owner', 'unit', 'tenant']),
        ]);
    }

    private function normalizePaymentState(array &$data, ?Invoice $existing = null): void
    {
        $status = $data['status'] ?? $existing?->status;

        if ($status === 'paid') {
            $paymentDate = $data['payment_date'] ?? $existing?->payment_date;
            if (empty($paymentDate)) {
                $data['payment_date'] = now();
            } elseif (is_string($paymentDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
                $data['payment_date'] = $paymentDate . ' ' . now()->format('H:i:s');
            }
            return;
        }

        if (array_key_exists('status', $data)) {
            $data['payment_date'] = null;
        }
    }
}
