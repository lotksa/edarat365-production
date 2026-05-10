<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Tenant;
use App\Services\EjarService;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json([
            'total'      => Contract::count(),
            'active'     => Contract::where('status', 'active')->count(),
            'expired'    => Contract::where('status', 'expired')->count(),
            'terminated' => Contract::where('status', 'terminated')->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Contract::with(['tenant', 'unit', 'owner', 'property']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', 'like', "%{$search}%")
                  ->orWhere('contract_name', 'like', "%{$search}%")
                  ->orWhere('party1_name', 'like', "%{$search}%")
                  ->orWhere('party2_name', 'like', "%{$search}%")
                  ->orWhere('tenant_name', 'like', "%{$search}%");
            });
        }

        if ($v = $request->query('status'))          $query->where('status', $v);
        if ($v = $request->query('contract_nature'))  $query->where('contract_nature', $v);
        if ($v = $request->query('contract_type'))    $query->where('contract_type', $v);

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

    public function show(int $id): JsonResponse
    {
        $contract = Contract::with(['tenant', 'unit.property.association', 'owner'])->findOrFail($id);
        return response()->json(['data' => $contract]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contract_name'             => ['required', 'string', 'max:255'],
            'contract_nature'           => ['nullable', 'string', 'max:100'],
            'contract_type'             => ['nullable', 'string', 'max:100'],
            'contract_date'             => ['nullable', 'date'],
            'venue'                     => ['nullable', 'string', 'max:255'],
            'venue_address'             => ['nullable', 'string', 'max:500'],
            'venue_city'                => ['nullable', 'string', 'max:100'],
            'party1_type'               => ['nullable', 'string', 'max:50'],
            'party1_name'               => ['nullable', 'string', 'max:255'],
            'party1_national_id'        => ['nullable', 'string', 'max:20'],
            'party1_phone'              => ['nullable', 'string', 'max:20'],
            'party1_email'              => ['nullable', 'email', 'max:255'],
            'party1_address'            => ['nullable', 'string', 'max:500'],
            'party2_type'               => ['nullable', 'string', 'max:50'],
            'party2_name'               => ['nullable', 'string', 'max:255'],
            'party2_national_id'        => ['nullable', 'string', 'max:20'],
            'party2_phone'              => ['nullable', 'string', 'max:20'],
            'party2_email'              => ['nullable', 'email', 'max:255'],
            'party2_address'            => ['nullable', 'string', 'max:500'],
            'preamble'                  => ['nullable', 'string'],
            'contract_clauses'          => ['nullable', 'array'],
            'contract_clauses.*.title'  => ['required_with:contract_clauses', 'string', 'max:255'],
            'contract_clauses.*.content'=> ['required_with:contract_clauses', 'string'],
            'start_date'                => ['nullable', 'date'],
            'end_date'                  => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes'                     => ['nullable', 'string'],
            'status'                    => ['nullable', 'string', 'max:50'],
            'unit_id'                   => ['nullable', 'exists:units,id'],
            'owner_id'                  => ['nullable', 'exists:owners,id'],
            'property_id'               => ['nullable', 'exists:properties,id'],
            'tenant_id'                 => ['nullable', 'exists:tenants,id'],
            'tenant_name'               => ['nullable', 'string', 'max:255'],
            'rental_amount'             => ['nullable', 'numeric', 'min:0'],
            'payment_type'              => ['nullable', 'string', 'max:50'],
            'contract_period'           => ['nullable', 'string', 'max:50'],
        ], [
            'contract_name.required'  => 'اسم العقد مطلوب',
        ]);

        $data['contract_number'] = 'CTR-' . date('Y') . '-' . str_pad((Contract::max('id') ?? 0) + 1, 5, '0', STR_PAD_LEFT);
        $data['status'] = $data['status'] ?? 'active';

        $contract = Contract::create($data);
        ActivityLog::record('contract', $contract->id, 'created', 'تم إنشاء عقد — ' . $contract->contract_name);

        Notifier::dispatch('contract.created', [
            'subject'  => $contract,
            'owner_id' => $contract->owner_id,
            'data'     => [
                'number' => $contract->contract_number,
                'name'   => $contract->contract_name,
            ],
        ]);

        return response()->json([
            'message' => 'تم إنشاء العقد بنجاح',
            'data'    => $contract->fresh(),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contract = Contract::findOrFail($id);

        $data = $request->validate([
            'contract_name'             => ['sometimes', 'string', 'max:255'],
            'contract_nature'           => ['nullable', 'string', 'max:100'],
            'contract_type'             => ['nullable', 'string', 'max:100'],
            'contract_date'             => ['nullable', 'date'],
            'venue'                     => ['nullable', 'string', 'max:255'],
            'venue_address'             => ['nullable', 'string', 'max:500'],
            'venue_city'                => ['nullable', 'string', 'max:100'],
            'party1_type'               => ['nullable', 'string', 'max:50'],
            'party1_name'               => ['nullable', 'string', 'max:255'],
            'party1_national_id'        => ['nullable', 'string', 'max:20'],
            'party1_phone'              => ['nullable', 'string', 'max:20'],
            'party1_email'              => ['nullable', 'email', 'max:255'],
            'party1_address'            => ['nullable', 'string', 'max:500'],
            'party2_type'               => ['nullable', 'string', 'max:50'],
            'party2_name'               => ['nullable', 'string', 'max:255'],
            'party2_national_id'        => ['nullable', 'string', 'max:20'],
            'party2_phone'              => ['nullable', 'string', 'max:20'],
            'party2_email'              => ['nullable', 'email', 'max:255'],
            'party2_address'            => ['nullable', 'string', 'max:500'],
            'preamble'                  => ['nullable', 'string'],
            'contract_clauses'          => ['nullable', 'array'],
            'start_date'                => ['nullable', 'date'],
            'end_date'                  => ['nullable', 'date'],
            'notes'                     => ['nullable', 'string'],
            'status'                    => ['nullable', 'string', 'max:50'],
            'unit_id'                   => ['nullable', 'exists:units,id'],
            'owner_id'                  => ['nullable', 'exists:owners,id'],
            'property_id'               => ['nullable', 'exists:properties,id'],
            'tenant_id'                 => ['nullable', 'exists:tenants,id'],
            'tenant_name'               => ['nullable', 'string', 'max:255'],
            'rental_amount'             => ['nullable', 'numeric', 'min:0'],
            'payment_type'              => ['nullable', 'string', 'max:50'],
            'contract_period'           => ['nullable', 'string', 'max:50'],
        ]);

        $contract->update($data);
        ActivityLog::record('contract', $contract->id, 'updated', 'تم تحديث عقد — ' . $contract->contract_name);

        return response()->json([
            'message' => 'تم تحديث العقد بنجاح',
            'data'    => $contract->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $contract = Contract::findOrFail($id);
        ActivityLog::record('contract', $contract->id, 'deleted', 'تم حذف عقد — ' . $contract->contract_name);
        $contract->delete();
        return response()->json(['message' => 'تم حذف العقد بنجاح']);
    }

    public function terminate(Request $request, int $id): JsonResponse
    {
        $contract = Contract::findOrFail($id);
        $reason = $request->input('reason', '');

        $contract->update([
            'status' => 'terminated',
            'notes'  => trim(($contract->notes ?? '') . "\n[إنهاء مبكر] " . $reason),
        ]);

        ActivityLog::record('contract', $contract->id, 'terminated', 'تم إنهاء العقد — ' . $contract->contract_name);

        return response()->json([
            'message' => 'تم إنهاء العقد بنجاح',
            'data'    => $contract->fresh(),
        ]);
    }

    public function clauses(): JsonResponse
    {
        return response()->json(['data' => EjarService::STANDARD_CLAUSES]);
    }
}
