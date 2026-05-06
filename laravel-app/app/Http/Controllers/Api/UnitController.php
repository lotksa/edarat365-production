<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitComponent;
use App\Models\UnitImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UnitController extends Controller
{
    private function baseRules(Request $request, ?int $ignoreId = null): array
    {
        $uniq = $ignoreId ? 'unique:units,unit_number,' . $ignoreId : 'unique:units,unit_number';
        return [
            'property_id'     => ['nullable', 'integer', 'exists:properties,id'],
            'unit_code'       => ['nullable', 'string', 'max:255'],
            'unit_number'     => [$request->input('status') === 'draft' ? 'nullable' : 'required', 'string', 'max:255', $uniq],
            'unit_type'       => ['nullable', 'string', 'max:100'],
            'description'     => ['nullable', 'string', 'max:1000'],
            'building_name'   => ['nullable', 'string', 'max:255'],
            'floor_number'    => ['nullable', 'integer'],
            'area'            => ['nullable', 'numeric', 'min:0'],
            'deed_number'     => ['nullable', 'string', 'max:255'],
            'deed_source'     => ['nullable', 'string', 'max:255'],
            'bedrooms'        => ['nullable', 'integer', 'min:0'],
            'bathrooms'       => ['nullable', 'integer', 'min:0'],
            'furnished'       => ['nullable', 'string', 'max:100'],
            'percentage'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status'          => ['nullable', 'string', 'in:active,vacant,occupied,maintenance,reserved,draft'],
            'monthly_rent'    => ['nullable', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string'],
        ];
    }

    private static function arMessages(): array
    {
        return [
            'unit_number.required' => 'رقم الوحدة مطلوب',
            'unit_number.unique'   => 'رقم الوحدة مستخدم مسبقاً',
            'property_id.exists'   => 'العقار المحدد غير موجود',
            'area.min'             => 'المساحة يجب أن تكون أكبر من صفر',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Unit::with(['property.association', 'owners', 'components']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('unit_number', 'like', "%{$search}%")
                  ->orWhere('unit_code', 'like', "%{$search}%")
                  ->orWhere('building_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('owners', function ($oq) use ($search) {
                      $oq->where('full_name', 'like', "%{$search}%")
                         ->orWhere('national_id', 'like', "%{$search}%");
                  });
            });
        }

        if ($status = $request->query('status'))     $query->where('status', $status);
        if ($unitType = $request->query('unit_type')) $query->where('unit_type', $unitType);
        if ($propertyId = $request->query('property_id')) $query->where('property_id', $propertyId);
        if ($ownerId = $request->query('owner_id')) {
            $query->whereHas('owners', fn ($q) => $q->where('owners.id', $ownerId));
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
            'total'    => Unit::count(),
            'active'   => Unit::where('status', 'active')->count(),
            'occupied' => Unit::where('status', 'occupied')->count(),
            'vacant'   => Unit::where('status', 'vacant')->count(),
            'draft'    => Unit::where('status', 'draft')->count(),
            'by_type'  => Unit::selectRaw('unit_type, count(*) as count')->groupBy('unit_type')->pluck('count', 'unit_type'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->baseRules($request), self::arMessages());
        $unit = Unit::create($data);
        ActivityLog::record('unit', $unit->id, 'created', 'تم إنشاء وحدة جديدة');

        return response()->json([
            'message' => 'تم إنشاء الوحدة بنجاح',
            'data'    => $unit,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $unit = Unit::with(['property.association', 'owners', 'invoices', 'contracts', 'components', 'images', 'maintenanceRequests'])->findOrFail($id);
        $data = $unit->toArray();
        $data['association_logo'] = $unit->property?->association?->logo ?? null;
        $data['activity_logs'] = ActivityLog::where('subject_type', 'unit')
            ->where('subject_id', $id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
        return response()->json(['data' => $data]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $unit = Unit::findOrFail($id);
        $old = $unit->status;
        $new = $old === 'active' ? 'vacant' : 'active';
        $unit->update(['status' => $new]);
        ActivityLog::record('unit', $id, 'status_changed', 'تم تغيير حالة الوحدة', ['status' => $old], ['status' => $new]);
        return response()->json(['message' => 'تم تحديث الحالة', 'data' => $unit->fresh()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $unit = Unit::findOrFail($id);
        $data = $request->validate($this->baseRules($request, $id), self::arMessages());
        $unit->update($data);

        if ($request->has('components')) {
            $unit->components()->delete();
            foreach ($request->input('components', []) as $comp) {
                if (!empty($comp['key']) && ($comp['quantity'] ?? 0) > 0) {
                    $unit->components()->create([
                        'component_key' => $comp['key'],
                        'quantity'      => (int) $comp['quantity'],
                    ]);
                }
            }
        }

        ActivityLog::record('unit', $id, 'updated', 'تم تحديث بيانات الوحدة');

        return response()->json([
            'message' => 'تم تحديث الوحدة بنجاح',
            'data'    => $unit->fresh()->load('components'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $settings = Setting::getByKey('unit_settings', []);
        if (!empty($settings['delete_protection'])) {
            return response()->json(['message' => 'حماية الحذف مفعّلة. يرجى تعطيلها من الإعدادات أولاً.'], 403);
        }

        $unit = Unit::findOrFail($id);
        ActivityLog::record('unit', $id, 'deleted', 'تم حذف الوحدة');
        $unit->delete();

        return response()->json(['message' => 'تم حذف الوحدة بنجاح']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $settings = Setting::getByKey('unit_settings', []);
        if (!empty($settings['delete_protection'])) {
            return response()->json(['message' => 'حماية الحذف مفعّلة. يرجى تعطيلها من الإعدادات أولاً.'], 403);
        }

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:units,id'],
        ]);

        $count = Unit::whereIn('id', $data['ids'])->delete();

        return response()->json([
            'message' => "تم حذف {$count} وحدة بنجاح",
            'count'   => $count,
        ]);
    }

    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer', 'exists:units,id'],
            'status' => ['required', 'string', 'in:active,vacant,occupied,maintenance,reserved,draft'],
        ]);

        $count = Unit::whereIn('id', $data['ids'])->update(['status' => $data['status']]);

        return response()->json([
            'message' => "تم تحديث {$count} وحدة بنجاح",
            'count'   => $count,
        ]);
    }

    /* ─── Unit Components ─── */

    public function storeComponents(Request $request, int $unitId): JsonResponse
    {
        $unit = Unit::findOrFail($unitId);
        $data = $request->validate([
            'components'                => ['required', 'array'],
            'components.*.key'          => ['required', 'string'],
            'components.*.quantity'     => ['required', 'integer', 'min:0'],
        ]);

        $unit->components()->delete();
        foreach ($data['components'] as $comp) {
            if ($comp['quantity'] > 0) {
                $unit->components()->create([
                    'component_key' => $comp['key'],
                    'quantity'      => $comp['quantity'],
                ]);
            }
        }

        return response()->json([
            'message' => 'تم حفظ مكونات الوحدة بنجاح',
            'data'    => $unit->components()->get(),
        ]);
    }

    /* ─── Unit Images ─── */

    public function uploadImages(Request $request, int $unitId): JsonResponse
    {
        $unit = Unit::findOrFail($unitId);
        $current = $unit->images()->count();

        $request->validate([
            'images'   => ['required', 'array', 'min:1', 'max:' . (10 - $current)],
            'images.*' => ['image', 'max:5120'],
        ], [
            'images.max' => 'يمكنك رفع ' . (10 - $current) . ' صور فقط كحد أقصى',
            'images.*.image' => 'الملف يجب أن يكون صورة',
            'images.*.max'   => 'حجم الصورة يجب ألا يتجاوز 5 ميجابايت',
        ]);

        if ($current >= 10) {
            return response()->json(['message' => 'تم بلوغ الحد الأقصى (10 صور)'], 422);
        }

        $uploaded = [];
        foreach ($request->file('images') as $idx => $file) {
            if ($current + $idx >= 10) break;
            $path = $file->store("units/{$unitId}/images", 'public');
            $uploaded[] = $unit->images()->create([
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'sort_order'    => $current + $idx,
            ]);
        }

        ActivityLog::record('unit', $unitId, 'images_uploaded', 'تم رفع ' . count($uploaded) . ' صورة للوحدة');

        return response()->json([
            'message' => 'تم رفع الصور بنجاح',
            'data'    => $uploaded,
        ]);
    }

    public function updateImage(Request $request, int $unitId, int $imageId): JsonResponse
    {
        $image = UnitImage::where('unit_id', $unitId)->findOrFail($imageId);
        $request->validate(['caption' => ['nullable', 'string', 'max:255']]);
        $image->update($request->only('caption'));
        return response()->json(['message' => 'تم التحديث', 'data' => $image]);
    }

    public function deleteImage(int $unitId, int $imageId): JsonResponse
    {
        $image = UnitImage::where('unit_id', $unitId)->findOrFail($imageId);
        Storage::disk('public')->delete($image->path);
        $image->delete();
        ActivityLog::record('unit', $unitId, 'image_deleted', 'تم حذف صورة من الوحدة');
        return response()->json(['message' => 'تم حذف الصورة']);
    }

    /* ─── Unit Owners (Multi-Owner) ─── */

    public function syncOwners(Request $request, int $unitId): JsonResponse
    {
        $unit = Unit::findOrFail($unitId);

        $data = $request->validate([
            'owners'                     => ['present', 'array'],
            'owners.*.owner_id'          => ['required', 'integer', 'exists:owners,id'],
            'owners.*.ownership_ratio'   => ['required', 'numeric', 'min:0.01', 'max:100'],
        ], [
            'owners.*.owner_id.exists'        => 'المالك المحدد غير موجود',
            'owners.*.ownership_ratio.min'    => 'نسبة الملكية يجب أن تكون أكبر من صفر',
            'owners.*.ownership_ratio.max'    => 'نسبة الملكية لا يمكن أن تتجاوز 100%',
        ]);

        $owners = $data['owners'];

        if (count($owners) > 0) {
            $total = array_sum(array_column($owners, 'ownership_ratio'));
            if (abs($total - 100) > 0.01) {
                return response()->json([
                    'message' => 'إجمالي نسب الملكية يجب أن يساوي 100%. الإجمالي الحالي: ' . round($total, 2) . '%',
                ], 422);
            }

            $ownerIds = array_column($owners, 'owner_id');
            if (count($ownerIds) !== count(array_unique($ownerIds))) {
                return response()->json([
                    'message' => 'لا يمكن إضافة نفس المالك أكثر من مرة',
                ], 422);
            }
        }

        $syncData = [];
        foreach ($owners as $o) {
            $syncData[$o['owner_id']] = ['ownership_ratio' => $o['ownership_ratio']];
        }

        $unit->owners()->sync($syncData);

        return response()->json([
            'message' => 'تم حفظ ملاك الوحدة بنجاح',
            'data'    => $unit->owners()->get(),
        ]);
    }
}
