<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    private function addressRules(): array
    {
        return [
            'has_national_address' => ['nullable', 'boolean'],
            'address_type'         => ['nullable', 'string', 'in:full,short'],
            'address_short_code'   => ['nullable', 'string', 'max:100'],
            'address_region'       => ['nullable', 'string', 'max:255'],
            'address_city'         => ['nullable', 'string', 'max:255'],
            'address_district'     => ['nullable', 'string', 'max:255'],
            'address_street'       => ['nullable', 'string', 'max:255'],
            'address_building_no'  => ['nullable', 'string', 'max:50'],
            'address_additional_no'=> ['nullable', 'string', 'max:50'],
            'address_postal_code'  => ['nullable', 'string', 'max:10'],
            'address_unit_no'      => ['nullable', 'string', 'max:50'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('national_id', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge([
            'full_name'   => ['required', 'string', 'max:255'],
            'national_id' => ['required', 'string', 'max:20'],
            'phone'       => ['nullable', 'string', 'max:20'],
            'email'       => ['nullable', 'email', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'status'      => ['nullable', 'string', 'in:active,inactive'],
        ], $this->addressRules()), [
            'full_name.required'     => 'اسم المستأجر مطلوب',
            'national_id.required'   => 'رقم الهوية مطلوب',
        ]);

        if (!($data['has_national_address'] ?? false)) {
            $data = array_merge($data, [
                'address_type' => null, 'address_short_code' => null,
                'address_region' => null, 'address_city' => null,
                'address_district' => null, 'address_street' => null,
                'address_building_no' => null, 'address_additional_no' => null,
                'address_postal_code' => null, 'address_unit_no' => null,
            ]);
        }

        $existing = Tenant::where('national_id', $data['national_id'])->first();
        if ($existing) {
            $existing->update($data);
            return response()->json([
                'message' => 'تم تحديث بيانات المستأجر',
                'data'    => $existing->fresh(),
            ]);
        }

        $tenant = Tenant::create($data);

        return response()->json([
            'message' => 'تم إضافة المستأجر بنجاح',
            'data'    => $tenant,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::with('contracts')->findOrFail($id);
        return response()->json(['data' => $tenant]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $data = $request->validate(array_merge([
            'full_name'   => ['required', 'string', 'max:255'],
            'national_id' => ['required', 'string', 'max:20', 'unique:tenants,national_id,' . $id],
            'phone'       => ['nullable', 'string', 'max:20'],
            'email'       => ['nullable', 'email', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'status'      => ['nullable', 'string', 'in:active,inactive'],
        ], $this->addressRules()), [
            'full_name.required'     => 'اسم المستأجر مطلوب',
            'national_id.required'   => 'رقم الهوية مطلوب',
            'national_id.unique'     => 'رقم الهوية مستخدم مسبقاً',
        ]);

        if (!($data['has_national_address'] ?? $tenant->has_national_address)) {
            $data = array_merge($data, [
                'address_type' => null, 'address_short_code' => null,
                'address_region' => null, 'address_city' => null,
                'address_district' => null, 'address_street' => null,
                'address_building_no' => null, 'address_additional_no' => null,
                'address_postal_code' => null, 'address_unit_no' => null,
            ]);
        }
        $tenant->update($data);

        return response()->json([
            'message' => 'تم تحديث بيانات المستأجر بنجاح',
            'data'    => $tenant->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->delete();

        return response()->json(['message' => 'تم حذف المستأجر بنجاح']);
    }
}
