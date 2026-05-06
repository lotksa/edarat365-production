<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::with(['association', 'property', 'owner', 'unit', 'invoice']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('transaction_number', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($v = $request->query('status'))              $query->where('status', $v);
        if ($v = $request->query('transaction_type'))     $query->where('transaction_type', $v);
        if ($v = $request->query('category'))             $query->where('category', $v);
        if ($v = $request->query('association_id'))        $query->where('association_id', $v);
        if ($v = $request->query('property_id'))           $query->where('property_id', $v);
        if ($v = $request->query('owner_id'))              $query->where('owner_id', $v);

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
            'total'     => Transaction::count(),
            'income'    => Transaction::where('transaction_type', 'income')->sum('amount'),
            'expense'   => Transaction::where('transaction_type', 'expense')->sum('amount'),
            'completed' => Transaction::where('status', 'completed')->count(),
            'pending'   => Transaction::where('status', 'pending')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $tx = Transaction::with(['association', 'property', 'owner', 'unit', 'invoice'])->findOrFail($id);
        return response()->json(['data' => $tx]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_type' => ['required', 'string', 'max:50'],
            'category'         => ['nullable', 'string', 'max:100'],
            'association_id'   => ['nullable', 'exists:associations,id'],
            'property_id'     => ['nullable', 'exists:properties,id'],
            'owner_id'        => ['nullable', 'exists:owners,id'],
            'unit_id'         => ['nullable', 'exists:units,id'],
            'invoice_id'      => ['nullable', 'exists:invoices,id'],
            'amount'          => ['required', 'numeric', 'min:0'],
            'payment_method'  => ['nullable', 'string', 'max:100'],
            'transaction_date'=> ['required', 'date'],
            'reference_number'=> ['nullable', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'notes'           => ['nullable', 'string'],
            'status'          => ['nullable', 'string', 'max:50'],
        ], [
            'transaction_type.required' => 'نوع الحركة مطلوب',
            'amount.required'           => 'المبلغ مطلوب',
            'transaction_date.required' => 'تاريخ الحركة مطلوب',
        ]);

        $data['transaction_number'] = 'TRX-' . date('Y') . '-' . str_pad((Transaction::max('id') ?? 0) + 1, 5, '0', STR_PAD_LEFT);
        $data['status'] = $data['status'] ?? 'completed';

        $tx = Transaction::create($data);
        ActivityLog::record('transaction', $tx->id, 'created', 'تم إنشاء حركة مالية — ' . $tx->transaction_number);

        return response()->json(['message' => 'تم إنشاء الحركة بنجاح', 'data' => $tx->load(['association', 'property', 'owner', 'unit', 'invoice'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tx = Transaction::findOrFail($id);

        $data = $request->validate([
            'transaction_type' => ['sometimes', 'string', 'max:50'],
            'category'         => ['nullable', 'string', 'max:100'],
            'association_id'   => ['nullable', 'exists:associations,id'],
            'property_id'     => ['nullable', 'exists:properties,id'],
            'owner_id'        => ['nullable', 'exists:owners,id'],
            'unit_id'         => ['nullable', 'exists:units,id'],
            'invoice_id'      => ['nullable', 'exists:invoices,id'],
            'amount'          => ['sometimes', 'numeric', 'min:0'],
            'payment_method'  => ['nullable', 'string', 'max:100'],
            'transaction_date'=> ['sometimes', 'date'],
            'reference_number'=> ['nullable', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'notes'           => ['nullable', 'string'],
            'status'          => ['nullable', 'string', 'max:50'],
        ]);

        $tx->update($data);
        ActivityLog::record('transaction', $tx->id, 'updated', 'تم تحديث حركة مالية — ' . $tx->transaction_number);

        return response()->json(['message' => 'تم تحديث الحركة بنجاح', 'data' => $tx->fresh()->load(['association', 'property', 'owner', 'unit', 'invoice'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        $tx = Transaction::findOrFail($id);
        ActivityLog::record('transaction', $tx->id, 'deleted', 'تم حذف حركة مالية — ' . $tx->transaction_number);
        $tx->delete();
        return response()->json(['message' => 'تم حذف الحركة بنجاح']);
    }
}
