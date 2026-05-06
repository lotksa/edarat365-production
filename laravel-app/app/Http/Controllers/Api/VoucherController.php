<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Voucher::with(['owner']);

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('voucher_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('owner', fn ($oq) => $oq->where('full_name', 'like', "%{$search}%"));
            });
        }

        if ($v = $request->query('payment_method')) $query->where('payment_method', $v);
        if ($v = $request->query('owner_id'))        $query->where('owner_id', $v);

        $perPage = (int) $request->query('per_page', 15);
        $records = $query->latest('id')->paginate($perPage);

        $items = collect($records->items())->map(function ($v) {
            $arr = $v->toArray();
            $arr['owner_name'] = $v->owner?->full_name ?? '-';
            return $arr;
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $base = $type ? Voucher::where('type', $type) : Voucher::query();

        return response()->json([
            'total'  => (clone $base)->count(),
            'amount' => (clone $base)->sum('amount'),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $voucher = Voucher::with(['owner', 'creator'])->findOrFail($id);
        return response()->json(['data' => $voucher]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'           => ['required', 'in:receipt,payment'],
            'owner_id'       => ['nullable', 'exists:owners,id'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'payment_date'   => ['nullable', 'date'],
            'description'    => ['nullable', 'string'],
            'notes'          => ['nullable', 'string'],
        ], [
            'type.required'   => 'نوع السند مطلوب',
            'amount.required' => 'المبلغ مطلوب',
        ]);

        $prefix = $data['type'] === 'receipt' ? 'RCV' : 'PMT';
        $data['voucher_number'] = $prefix . '-' . date('Y') . '-' . str_pad((Voucher::max('id') ?? 0) + 1, 5, '0', STR_PAD_LEFT);
        $data['created_by'] = auth()->id() ?? null;

        $voucher = Voucher::create($data);
        $label = $data['type'] === 'receipt' ? 'سند قبض' : 'سند صرف';
        ActivityLog::record('voucher', $voucher->id, 'created', "تم إنشاء {$label} — {$voucher->voucher_number}");

        return response()->json([
            'message' => 'تم إنشاء السند بنجاح',
            'data'    => $voucher->load(['owner']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);

        $data = $request->validate([
            'type'           => ['sometimes', 'in:receipt,payment'],
            'owner_id'       => ['nullable', 'exists:owners,id'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'amount'         => ['sometimes', 'numeric', 'min:0'],
            'payment_date'   => ['nullable', 'date'],
            'description'    => ['nullable', 'string'],
            'notes'          => ['nullable', 'string'],
        ]);

        $voucher->update($data);
        ActivityLog::record('voucher', $voucher->id, 'updated', 'تم تعديل سند — ' . $voucher->voucher_number);

        return response()->json([
            'message' => 'تم تعديل السند بنجاح',
            'data'    => $voucher->fresh()->load(['owner']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        ActivityLog::record('voucher', $voucher->id, 'deleted', 'تم حذف سند — ' . $voucher->voucher_number);
        $voucher->delete();

        return response()->json(['message' => 'تم حذف السند بنجاح']);
    }
}
