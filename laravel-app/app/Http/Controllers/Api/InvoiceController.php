<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
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

        if ($v = $request->query('status'))          $query->where('status', $v);
        if ($v = $request->query('invoice_type'))     $query->where('invoice_type', $v);
        if ($v = $request->query('association_id'))    $query->where('association_id', $v);
        if ($v = $request->query('property_id'))       $query->where('property_id', $v);
        if ($v = $request->query('owner_id'))          $query->where('owner_id', $v);

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
            'total'    => Invoice::count(),
            'pending'  => Invoice::where('status', 'pending')->count(),
            'paid'     => Invoice::where('status', 'paid')->count(),
            'overdue'  => Invoice::where('status', 'overdue')->count(),
            'partial'  => Invoice::where('status', 'partial')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with(['association', 'property', 'owner', 'unit', 'tenant'])->findOrFail($id);
        return response()->json(['data' => $invoice]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
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
            'description'     => ['nullable', 'string'],
            'line_items'      => ['nullable', 'array'],
            'notes'           => ['nullable', 'string'],
            'status'          => ['nullable', 'string', 'max:50'],
        ]);

        $data['invoice_number'] = 'INV-' . date('Y') . '-' . str_pad((Invoice::max('id') ?? 0) + 1, 5, '0', STR_PAD_LEFT);
        $data['status'] = $data['status'] ?? 'unpaid';
        $data['issue_date'] = $data['issue_date'] ?? now()->toDateString();

        $subtotal = (float) ($data['amount'] ?? 0);
        $vatRate = (float) ($data['vat_rate'] ?? 0);
        $discount = (float) ($data['discount_amount'] ?? 0);
        $taxAmt = $subtotal * ($vatRate / 100);
        $data['tax_amount'] = round($taxAmt, 2);
        $data['total_amount'] = $data['total_amount'] ?? round($subtotal + $taxAmt - $discount, 2);

        $invoice = Invoice::create($data);
        ActivityLog::record('invoice', $invoice->id, 'created', 'تم إنشاء فاتورة — ' . $invoice->invoice_number);

        return response()->json(['message' => 'تم إنشاء الفاتورة بنجاح', 'data' => $invoice->load(['association', 'property', 'owner', 'unit', 'tenant'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $data = $request->validate([
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
            'description'     => ['nullable', 'string'],
            'line_items'      => ['nullable', 'array'],
            'notes'           => ['nullable', 'string'],
            'status'          => ['nullable', 'string', 'max:50'],
        ]);

        $subtotal = (float) ($data['amount'] ?? $invoice->amount);
        $vatRate = (float) ($data['vat_rate'] ?? $invoice->vat_rate ?? 0);
        $discount = (float) ($data['discount_amount'] ?? $invoice->discount_amount ?? 0);
        $taxAmt = $subtotal * ($vatRate / 100);
        $data['tax_amount'] = round($taxAmt, 2);
        $data['total_amount'] = $data['total_amount'] ?? round($subtotal + $taxAmt - $discount, 2);

        $invoice->update($data);
        ActivityLog::record('invoice', $invoice->id, 'updated', 'تم تحديث فاتورة — ' . $invoice->invoice_number);

        return response()->json(['message' => 'تم تحديث الفاتورة بنجاح', 'data' => $invoice->fresh()->load(['association', 'property', 'owner', 'unit', 'tenant'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        ActivityLog::record('invoice', $invoice->id, 'deleted', 'تم حذف فاتورة — ' . $invoice->invoice_number);
        $invoice->delete();
        return response()->json(['message' => 'تم حذف الفاتورة بنجاح']);
    }
}
