<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    /**
     * Statuses considered "draft" — only drafts may be freely edited or
     * deleted. Anything else is treated as ISSUED for ZATCA purposes.
     */
    private const DRAFT_STATUSES = ['draft'];

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
        if ($v = $request->query('association_id'))   $query->where('association_id', $v);
        if ($v = $request->query('property_id'))      $query->where('property_id', $v);
        if ($v = $request->query('owner_id'))         $query->where('owner_id', $v);

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
            'pending'   => Invoice::where('status', 'pending')->count(),
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

        // Default status: 'unpaid' unless caller explicitly sent 'draft'.
        $data['status'] = $data['status'] ?? 'unpaid';
        $data['issue_date'] = $data['issue_date'] ?? now()->toDateString();

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

        return response()->json([
            'message' => 'تم إنشاء الفاتورة بنجاح',
            'data'    => $invoice->load(['association', 'property', 'owner', 'unit', 'tenant']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        // ZATCA: only DRAFT invoices may be edited. After issuance, the
        // legal flow is cancel + reissue, never in-place modification.
        if ($invoice->is_locked) {
            return response()->json([
                'message' => 'لا يمكن تعديل فاتورة مُصدرة أو ملغاة. يجب إلغاء الفاتورة الحالية وإصدار فاتورة جديدة.',
                'reason'  => 'zatca_locked',
                'is_locked' => true,
            ], 422);
        }

        $data = $this->validateInvoicePayload($request);

        $oldStatus = $invoice->status;
        $newStatus = $data['status'] ?? $oldStatus;

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
        $subtotal = (float) ($data['amount'] ?? $existing->amount ?? 0);
        $vatRate  = (float) ($data['vat_rate'] ?? $existing->vat_rate ?? 0);
        $discount = (float) ($data['discount_amount'] ?? $existing->discount_amount ?? 0);
        $taxAmt = $subtotal * ($vatRate / 100);
        $data['tax_amount']   = round($taxAmt, 2);
        $data['total_amount'] = $data['total_amount'] ?? round($subtotal + $taxAmt - $discount, 2);
    }
}
