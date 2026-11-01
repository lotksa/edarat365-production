<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Association;
use App\Models\Contract;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\MaintenanceRequest;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\AssociationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** System summaries are read-only. Writes still use the existing scoped controllers. */
final class ExecutiveRecordsController extends Controller
{
    public function review(Request $request, string $module)
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        [$class, $permission, $label] = self::MODULES[$module];
        $permission = str_replace('.view', '.update', $permission);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->hasPermission($permission), 403);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct'],
            'association_id' => ['nullable', 'integer', 'exists:associations,id']]);
        $context = app(AssociationContext::class);
        $context->id = isset($data['association_id']) ? (int) $data['association_id'] : null;
        try {
            return DB::transaction(function () use ($class, $module, $data, $label) {
                $query = $class::whereIn('id', $data['ids']);
                if (in_array($module, ['vouchers', 'expenses'])) {
                    $query->where('type', $module === 'vouchers' ? 'receipt' : 'payment');
                }
                $rows = $query->lockForUpdate()->get();
                if ($rows->count() !== count($data['ids'])) {
                    throw ValidationException::withMessages(['ids' => ['بعض السجلات غير موجودة ضمن النطاق الحالي.']]);
                }
                $type = ['properties' => 'property', 'units' => 'unit', 'owners' => 'owner', 'facilities' => 'facility',
                    'invoices' => 'invoice', 'vouchers' => 'voucher', 'expenses' => 'voucher', 'contracts' => 'contract',
                    'maintenance' => 'maintenance', 'cases' => 'legal_case'][$module];
                foreach ($rows as $row) {
                    ActivityLog::record($type, $row->id, 'reviewed', 'تمت مراجعة السجل — '.$row->$label);
                }

                return response()->json(['count' => $rows->count(), 'message' => 'تم تسجيل مراجعة السجلات المحددة']);
            });
        } finally {
            $context->id = null;
        }
    }

    private const MODULES = [
        'properties' => [Property::class, 'properties.view', 'name', 'property_number'],
        'units' => [Unit::class, 'units.view', 'unit_number', 'unit_code'],
        'owners' => [Owner::class, 'owners.view', 'full_name', 'account_number'],
        'facilities' => [Facility::class, 'facilities.view', 'name', 'name'],
        'invoices' => [Invoice::class, 'invoices.view', 'invoice_number', 'invoice_number'],
        'vouchers' => [Voucher::class, 'vouchers.view', 'voucher_number', 'voucher_number'],
        'expenses' => [Voucher::class, 'vouchers.view', 'voucher_number', 'voucher_number'],
        'contracts' => [Contract::class, 'contracts.view', 'contract_name', 'contract_number'],
        'maintenance' => [MaintenanceRequest::class, 'maintenance.view', 'title', 'title'],
        'cases' => [LegalCase::class, 'legal_cases.view', 'title', 'case_number'],
    ];

    public function index(Request $request, string $module)
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        [$class, $permission, $label, $code] = self::MODULES[$module];
        abort_unless($request->user()->isSuperAdmin() || $request->user()->hasPermission($permission), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'string', 'max:50'],
            'association_id' => ['nullable', 'integer', 'exists:associations,id'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1']]);
        $context = app(AssociationContext::class);
        $context->id = isset($data['association_id']) ? (int) $data['association_id'] : null;
        try {
            $query = $class::query();
            if (filled($data['q'] ?? null)) {
                $query->where(fn ($q) => $q->where($label, 'like', '%'.$data['q'].'%')->orWhere($code, 'like', '%'.$data['q'].'%'));
            }
            if ($module === 'expenses') {
                $query->where('type', 'payment');
            } elseif ($module === 'vouchers') {
                $query->where('type', 'receipt');
            }
            if (filled($data['status'] ?? null)) {
                if ($module === 'facilities') {
                    $query->where('is_active', $data['status'] === 'active');
                } else {
                    $query->where('status', $data['status']);
                }
            }
            if ($module === 'owners') {
                $query->with(['units.property:id,name,association_id', 'properties:id,name,association_id'])->withCount('units');
            } elseif ($module === 'properties') {
                $query->withCount('units');
            } elseif ($module === 'units') {
                $query->with(['property:id,name,association_id', 'owners:id,full_name']);
            } elseif (in_array($module, ['facilities', 'maintenance'])) {
                $query->with('property:id,name,association_id');
            }
            $names = Association::get(['id', 'name', 'name_en'])->keyBy('id');
            $rows = $query->orderByDesc('id')->paginate($data['per_page'] ?? 20);
            $items = $rows->getCollection()->map(function ($r) use ($module, $label, $code, $names) {
                $associationId = $r->association_id ?? $r->property?->association_id;
                if ($module === 'owners') {
                    $associationId ??= $r->properties->first()?->association_id ?? $r->units->first()?->property?->association_id;
                }
                $first = $second = null;
                switch ($module) {
                    case 'properties': $first = $r->type;
                        $second = $r->units_count;
                        break;
                    case 'units': $first = $r->property?->name;
                        $second = $r->owners->pluck('full_name')->join('، ');
                        break;
                    case 'owners': $first = $r->email;
                        $second = $r->units_count;
                        break;
                    case 'facilities': $first = $r->property?->name;
                        $second = $r->facility_type;
                        break;
                    case 'invoices': $first = $r->total_amount ?? $r->amount;
                        $second = $r->due_date;
                        break;
                    case 'vouchers': case 'expenses': $first = $r->amount;
                        $second = $r->payment_date;
                        break;
                    case 'contracts': $first = $r->rental_amount;
                        $second = $r->end_date;
                        break;
                    case 'maintenance': $first = $r->property?->name;
                        $second = $r->priority;
                        break;
                    case 'cases': $first = $r->hearing_date;
                        $second = $r->amount;
                        break;
                }

                return ['id' => $r->id, 'label' => $r->$label ?: $r->$code, 'code' => $r->$code,
                    'association_id' => $contextId = app(AssociationContext::class)->id ?? $associationId,
                    'association_name' => $names[$contextId]->name ?? null, 'association_name_en' => $names[$contextId]->name_en ?? null,
                    'status' => $module === 'facilities' ? ($r->is_active ? 'active' : 'inactive') : ($r->cancelled_at ? 'cancelled' : $r->status),
                    'first' => $first, 'second' => $second];
            });

            return response()->json(['data' => $items, 'meta' => ['current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()]]);
        } finally {
            $context->id = null;
        }
    }
}
