<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Association;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AssociationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Association::with(['manager', 'city', 'district']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('name_en', 'like', "%{$search}%")
                  ->orWhere('registration_number', 'like', "%{$search}%")
                  ->orWhere('association_number', 'like', "%{$search}%")
                  ->orWhere('unified_number', 'like', "%{$search}%");
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

    public function stats(): JsonResponse
    {
        return response()->json([
            'total'    => Association::count(),
            'active'   => Association::where('status', 'active')->count(),
            'inactive' => Association::where('status', 'inactive')->count(),
            'draft'    => Association::where('status', 'draft')->count(),
        ]);
    }

    private function castBooleans(Request $request): void
    {
        foreach (['has_commission', 'has_national_address'] as $field) {
            if ($request->has($field)) {
                $request->merge([
                    $field => filter_var($request->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
                ]);
            }
        }
    }

    private function validationRules(Request $request, bool $isUpdate = false): array
    {
        return [
            'name'                => [$isUpdate ? 'sometimes' : ($request->input('status') === 'draft' ? 'nullable' : 'required'), 'string', 'max:255'],
            'name_en'             => ['nullable', 'string', 'max:255'],
            'logo'                => ['nullable', 'image', 'max:2048'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'association_number'  => ['nullable', 'string', 'max:255'],
            'established_date'    => ['nullable', 'date'],
            'first_approval_date' => ['nullable', 'date'],
            'expiry_date'         => ['nullable', 'date'],
            'unified_number'      => ['nullable', 'string', 'max:255'],
            'establishment_number'=> ['nullable', 'string', 'max:255'],
            'status'              => ['nullable', 'string', 'in:active,inactive,draft'],
            'management_model'    => ['nullable', 'string', 'max:255'],
            'latitude'            => ['nullable', 'numeric'],
            'longitude'           => ['nullable', 'numeric'],
            'city_id'             => ['nullable', 'integer', 'exists:cities,id'],
            'district_id'         => ['nullable', 'integer', 'exists:districts,id'],
            'manager_id'          => ['nullable', 'integer', 'exists:association_managers,id'],
            'manager_start_date'  => ['nullable', 'date'],
            'manager_end_date'    => ['nullable', 'date'],
            'manager_salary'      => ['nullable', 'numeric', 'min:0'],
            'has_commission'      => ['nullable', 'boolean'],
            'commission_type'     => ['nullable', 'string', 'in:fixed,percentage'],
            'commission_value'    => ['nullable', 'numeric', 'min:0'],
            'has_national_address' => ['nullable', 'boolean'],
            'address_type'        => ['nullable', 'string', 'in:full,short'],
            'address_short_code'  => ['nullable', 'string', 'max:255'],
            'address_region'      => ['nullable', 'string', 'max:255'],
            'address_city_name'   => ['nullable', 'string', 'max:255'],
            'address_district'    => ['nullable', 'string', 'max:255'],
            'address_street'      => ['nullable', 'string', 'max:255'],
            'address_building_no' => ['nullable', 'string', 'max:50'],
            'address_additional_no' => ['nullable', 'string', 'max:50'],
            'address_postal_code' => ['nullable', 'string', 'max:10'],
            'address_unit_no'     => ['nullable', 'string', 'max:50'],
        ];
    }

    private static function arMessages(): array
    {
        return [
            'name.required'          => 'اسم الجمعية مطلوب',
            'name.string'            => 'اسم الجمعية يجب أن يكون نصاً',
            'name.max'               => 'اسم الجمعية يجب ألا يتجاوز 255 حرفاً',
            'logo.image'             => 'الشعار يجب أن يكون صورة',
            'logo.max'               => 'حجم الشعار يجب ألا يتجاوز 2 ميجابايت',
            'status.in'              => 'الحالة غير صالحة',
            'city_id.exists'         => 'المدينة المحددة غير موجودة',
            'district_id.exists'     => 'الحي المحدد غير موجود',
            'manager_id.exists'      => 'الرئيس المحدد غير موجود',
            'manager_salary.numeric' => 'الأجر يجب أن يكون رقماً',
            'manager_salary.min'     => 'الأجر يجب أن يكون أكبر من أو يساوي صفر',
            'commission_value.numeric' => 'قيمة العمولة يجب أن تكون رقماً',
            'commission_value.min'   => 'قيمة العمولة يجب أن تكون أكبر من أو يساوي صفر',
            'latitude.numeric'       => 'خط العرض يجب أن يكون رقماً',
            'longitude.numeric'      => 'خط الطول يجب أن يكون رقماً',
        ];
    }

    private function handleLogo(Request $request, ?Association $existing = null): ?string
    {
        if ($request->hasFile('logo')) {
            if ($existing && $existing->logo) {
                Storage::disk('public')->delete($existing->logo);
            }
            return $request->file('logo')->store('associations/logos', 'public');
        }
        return null;
    }

    public function store(Request $request): JsonResponse
    {
        $this->castBooleans($request);
        $data = $request->validate($this->validationRules($request), self::arMessages());

        if (empty($data['has_national_address'])) {
            $addressFields = ['address_type','address_short_code','address_region','address_city_name','address_district','address_street','address_building_no','address_additional_no','address_postal_code','address_unit_no'];
            foreach ($addressFields as $f) { $data[$f] = null; }
            $data['address_type'] = 'full';
        }

        $logoPath = $this->handleLogo($request);
        if ($logoPath) {
            $data['logo'] = $logoPath;
        } else {
            unset($data['logo']);
        }

        $association = Association::create($data);
        ActivityLog::record('association', $association->id, 'created', 'تم إنشاء جمعية جديدة');

        return response()->json([
            'message' => 'تم إنشاء الجمعية بنجاح',
            'data'    => $association->load(['manager', 'city', 'district']),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $association = Association::with([
            'properties.units', 'manager', 'city', 'district',
            'facilities', 'bookings.facility', 'bookings.owner',
        ])->findOrFail($id);

        $data = $association->toArray();
        $data['association_settings'] = Setting::getByKey('association_settings', []);

        $data['meetings'] = \App\Models\Meeting::where('association_id', $id)
            ->orderByDesc('scheduled_at')->limit(50)->get();

        $data['invoices'] = \App\Models\Invoice::where(function ($q) use ($id) {
            $q->where('association_id', $id)
              ->orWhereHas('property', fn($q2) => $q2->where('association_id', $id))
              ->orWhereHas('unit', fn($q2) => $q2->whereHas('property', fn($q3) => $q3->where('association_id', $id)));
        })->orderByDesc('id')->limit(50)->get();

        $data['contracts'] = \App\Models\Contract::where(function ($q) use ($id) {
            $q->whereHas('property', fn($q2) => $q2->where('association_id', $id))
              ->orWhereHas('unit', fn($q2) => $q2->whereHas('property', fn($q3) => $q3->where('association_id', $id)));
        })->orderByDesc('id')->limit(50)->get();

        $data['maintenance_requests'] = \App\Models\MaintenanceRequest::where(function ($q) use ($id) {
            $q->where('association_id', $id)
              ->orWhereHas('unit', fn($q2) => $q2->whereHas('property', fn($q3) => $q3->where('association_id', $id)));
        })->with(['owner', 'unit'])->orderByDesc('id')->limit(50)->get();

        $legalCases = \App\Models\LegalCase::where('association_id', $id)
            ->orderByDesc('id')->limit(50)->get();
        $data['legal_cases'] = $legalCases;

        $data['stats'] = [
            'properties' => ['total' => count($data['properties'] ?? [])],
            'facilities' => [
                'total' => count($data['facilities'] ?? []),
                'bookable' => collect($data['facilities'] ?? [])->where('is_bookable', true)->count(),
                'active' => collect($data['facilities'] ?? [])->where('is_active', true)->count(),
            ],
            'meetings' => [
                'total' => count($data['meetings'] ?? []),
                'scheduled' => collect($data['meetings'])->where('status', 'scheduled')->count(),
            ],
            'invoices' => [
                'total' => count($data['invoices'] ?? []),
                'total_amount' => collect($data['invoices'])->sum('total_amount'),
            ],
            'maintenance' => [
                'total' => count($data['maintenance_requests'] ?? []),
                'open' => collect($data['maintenance_requests'])->where('status', 'open')->count(),
                'in_progress' => collect($data['maintenance_requests'])->where('status', 'in_progress')->count(),
                'completed' => collect($data['maintenance_requests'])->where('status', 'completed')->count(),
            ],
            'contracts' => [
                'total' => count($data['contracts'] ?? []),
                'active' => collect($data['contracts'])->where('status', 'active')->count(),
            ],
            'legal_cases' => ['total' => count($legalCases)],
            'bookings_today' => \App\Models\Booking::where('association_id', $id)
                ->whereDate('starts_at', today())->where('status', 'approved')->count(),
        ];

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $association = Association::findOrFail($id);
        $this->castBooleans($request);
        $data = $request->validate($this->validationRules($request, true), self::arMessages());

        if (empty($data['has_national_address'])) {
            $addressFields = ['address_type','address_short_code','address_region','address_city_name','address_district','address_street','address_building_no','address_additional_no','address_postal_code','address_unit_no'];
            foreach ($addressFields as $f) { $data[$f] = null; }
            $data['address_type'] = 'full';
        }

        $logoPath = $this->handleLogo($request, $association);
        if ($logoPath) {
            $data['logo'] = $logoPath;
        } else {
            unset($data['logo']);
        }

        $association->update($data);
        ActivityLog::record('association', $id, 'updated', 'تم تحديث بيانات الجمعية');

        return response()->json([
            'message' => 'تم تحديث بيانات الجمعية بنجاح',
            'data'    => $association->fresh()->load(['manager', 'city', 'district']),
        ]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $a = Association::findOrFail($id);
        $oldStatus = $a->status;
        $a->status = $a->status === 'active' ? 'inactive' : 'active';
        $a->save();
        ActivityLog::record('association', $id, 'status_changed', $a->status === 'active' ? 'تم تفعيل الجمعية' : 'تم إيقاف الجمعية', ['status' => $oldStatus], ['status' => $a->status]);

        return response()->json([
            'message' => $a->status === 'active' ? 'تم تفعيل الجمعية بنجاح' : 'تم إيقاف الجمعية بنجاح',
            'data'    => $a->fresh()->load(['manager', 'city', 'district']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $association = Association::findOrFail($id);

        $settings = Setting::getByKey('association_settings', []);
        $protectionEnabled = $settings['delete_protection'] ?? true;

        if ($protectionEnabled) {
            $hasProperties = $association->properties()->exists();
            $reasons = [];
            if ($hasProperties) {
                $reasons[] = 'properties';
            }
            if ($reasons) {
                return response()->json([
                    'message'   => 'لا يمكن حذف الجمعية لوجود بيانات مرتبطة بها. يرجى حذف البيانات المرتبطة أولاً أو تعطيل حماية الحذف من الإعدادات.',
                    'protected' => true,
                    'reasons'   => $reasons,
                ], 422);
            }
        }

        ActivityLog::record('association', $id, 'deleted', 'تم حذف الجمعية');
        $association->delete();
        return response()->json(['message' => 'تم حذف الجمعية بنجاح']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:associations,id'],
        ]);
        $count = Association::whereIn('id', $data['ids'])->delete();
        return response()->json(['message' => "تم حذف {$count} جمعيات بنجاح", 'count' => $count]);
    }

    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer', 'exists:associations,id'],
            'status' => ['required', 'string', 'in:active,inactive,draft'],
        ]);
        $count = Association::whereIn('id', $data['ids'])->update(['status' => $data['status']]);
        return response()->json(['message' => "تم تحديث حالة {$count} جمعيات بنجاح", 'count' => $count]);
    }
}
