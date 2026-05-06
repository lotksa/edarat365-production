<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ApprovalRequest;
use App\Models\PropertyComponent;
use App\Models\Contract;
use App\Models\Facility;
use App\Models\LegalCase;
use App\Models\MaintenanceRequest;
use App\Models\Meeting;
use App\Models\Owner;
use App\Models\Property;
use App\Models\PropertyDocument;
use App\Models\Setting;
use App\Models\UtilityMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Property::with(['association', 'cityRelation', 'districtRelation']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('property_number', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('district', 'like', "%{$search}%");
            });
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($associationId = $request->query('association_id')) {
            $query->where('association_id', $associationId);
        }

        $perPage = (int) $request->query('per_page', 15);
        $records = $query->latest('id')->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => Property::count(),
            'active' => Property::where('status', 'active')->count(),
            'draft' => Property::where('status', 'draft')->count(),
            'by_type' => Property::selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_number' => ['nullable', 'string', 'max:255', 'unique:properties,property_number'],
            'name' => [$request->input('status') === 'draft' ? 'nullable' : 'required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'association_id' => ['nullable', 'integer', 'exists:associations,id'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'total_units' => ['nullable', 'integer', 'min:0'],
            'total_floors' => ['nullable', 'integer', 'min:0'],
            'year_built' => ['nullable', 'integer', 'min:1200', 'max:2100'],
            'build_date_type' => ['nullable', 'string', 'in:year,full_date'],
            'build_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,inactive,under_construction,draft'],
            'notes' => ['nullable', 'string'],
            'plot_number' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'green_area' => ['nullable', 'numeric', 'min:0'],
            'deed_number' => ['nullable', 'string', 'max:255'],
            'deed_source' => ['nullable', 'string', 'max:100'],
            'total_elevators' => ['nullable', 'integer', 'min:0'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'property_manager_id' => ['nullable', 'integer', 'exists:property_managers,id'],
        ]);

        if (($data['build_date_type'] ?? 'year') === 'full_date') {
            $data['year_built'] = $data['build_date'] ? (int) date('Y', strtotime($data['build_date'])) : null;
        } else {
            $data['build_date'] = null;
        }

        $property = Property::create($data);
        ActivityLog::record('property', $property->id, 'created', 'تم إنشاء عقار جديد');

        return response()->json([
            'message' => 'Property created successfully',
            'data' => $property,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $property = Property::with(['association', 'units', 'propertyManager', 'utilityMeters', 'documents', 'cityRelation', 'districtRelation', 'components'])->findOrFail($id);

        return response()->json(['data' => $property]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $property = Property::findOrFail($id);

        $data = $request->validate([
            'property_number' => ['nullable', 'string', 'max:255', 'unique:properties,property_number,' . $id],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'association_id' => ['nullable', 'integer', 'exists:associations,id'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'total_units' => ['nullable', 'integer', 'min:0'],
            'total_floors' => ['nullable', 'integer', 'min:0'],
            'year_built' => ['nullable', 'integer', 'min:1200', 'max:2100'],
            'build_date_type' => ['nullable', 'string', 'in:year,full_date'],
            'build_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,inactive,under_construction,draft'],
            'notes' => ['nullable', 'string'],
            'plot_number' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'green_area' => ['nullable', 'numeric', 'min:0'],
            'deed_number' => ['nullable', 'string', 'max:255'],
            'deed_source' => ['nullable', 'string', 'max:100'],
            'total_elevators' => ['nullable', 'integer', 'min:0'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'property_manager_id' => ['nullable', 'integer', 'exists:property_managers,id'],
        ]);

        if (($data['build_date_type'] ?? $property->build_date_type ?? 'year') === 'full_date') {
            $data['year_built'] = $data['build_date'] ? (int) date('Y', strtotime($data['build_date'])) : null;
        } else {
            $data['build_date'] = null;
        }

        $property->update($data);
        ActivityLog::record('property', $id, 'updated', 'تم تحديث بيانات العقار');

        return response()->json([
            'message' => 'Property updated successfully',
            'data' => $property->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $property = Property::findOrFail($id);

        $settings = Setting::getByKey('property_settings', ['delete_protection' => true]);
        if ($settings['delete_protection'] ?? true) {
            $unitCount = $property->units()->count();
            if ($unitCount > 0) {
                return response()->json([
                    'message' => 'لا يمكن حذف العقار لأنه يحتوي على وحدات عقارية مرتبطة',
                    'message_en' => 'Cannot delete property with linked units',
                ], 409);
            }
        }

        ActivityLog::record('property', $id, 'deleted', 'تم حذف العقار');
        $property->delete();

        return response()->json(['message' => 'Property deleted successfully']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:properties,id'],
        ]);

        $count = Property::whereIn('id', $data['ids'])->delete();

        return response()->json([
            'message' => "Deleted {$count} properties",
            'count' => $count,
        ]);
    }

    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:properties,id'],
            'status' => ['required', 'string', 'in:active,inactive,under_construction,draft'],
        ]);

        $count = Property::whereIn('id', $data['ids'])->update(['status' => $data['status']]);

        return response()->json([
            'message' => "Updated {$count} properties",
            'count' => $count,
        ]);
    }

    // ── Utility Meters ──────────────────────────────────────────────

    public function listUtilityMeters(int $propertyId): JsonResponse
    {
        $property = Property::findOrFail($propertyId);

        return response()->json(['data' => $property->utilityMeters]);
    }

    public function storeUtilityMeter(Request $request, int $propertyId): JsonResponse
    {
        Property::findOrFail($propertyId);

        if ($request->has('meters')) {
            $request->validate([
                'meters'                => ['required', 'array', 'min:1'],
                'meters.*.type'         => ['required', 'string', 'in:water,electricity'],
                'meters.*.meter_number' => ['required', 'string', 'max:255'],
                'meters.*.account_number' => ['nullable', 'string', 'max:255'],
                'meters.*.account_type'   => ['nullable', 'string', 'max:255'],
            ]);

            UtilityMeter::where('property_id', $propertyId)->delete();

            $created = [];
            foreach ($request->input('meters') as $m) {
                $created[] = UtilityMeter::create([
                    'property_id'    => $propertyId,
                    'meter_type'     => $m['type'],
                    'meter_number'   => $m['meter_number'],
                    'account_number' => $m['account_number'] ?? null,
                    'account_type'   => $m['account_type'] ?? null,
                ]);
            }

            return response()->json(['message' => 'Meters saved', 'data' => $created], 201);
        }

        $data = $request->validate([
            'meter_type'     => ['required', 'string', 'in:water,electricity'],
            'meter_number'   => ['required', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'account_type'   => ['nullable', 'string', 'max:255'],
        ]);

        $data['property_id'] = $propertyId;
        $meter = UtilityMeter::create($data);

        return response()->json(['message' => 'Meter created', 'data' => $meter], 201);
    }

    public function updateUtilityMeter(Request $request, int $propertyId, int $meterId): JsonResponse
    {
        Property::findOrFail($propertyId);
        $meter = UtilityMeter::where('property_id', $propertyId)->findOrFail($meterId);

        $data = $request->validate([
            'meter_type'     => ['sometimes', 'string', 'in:water,electricity'],
            'meter_number'   => ['sometimes', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'account_type'   => ['nullable', 'string', 'max:255'],
        ]);

        $meter->update($data);

        return response()->json(['message' => 'Meter updated', 'data' => $meter->fresh()]);
    }

    public function destroyUtilityMeter(int $propertyId, int $meterId): JsonResponse
    {
        Property::findOrFail($propertyId);
        UtilityMeter::where('property_id', $propertyId)->findOrFail($meterId)->delete();

        return response()->json(['message' => 'Meter deleted']);
    }

    // ── Property Documents ──────────────────────────────────────────

    // ── Property Components ──────────────────────────────────────

    public function storeComponents(Request $request, int $propertyId): JsonResponse
    {
        Property::findOrFail($propertyId);

        $request->validate([
            'components' => ['required', 'array'],
            'components.*.key' => ['required', 'string', 'max:100'],
            'components.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        PropertyComponent::where('property_id', $propertyId)->delete();

        $saved = [];
        foreach ($request->input('components') as $c) {
            if ((int) $c['quantity'] > 0) {
                $saved[] = PropertyComponent::create([
                    'property_id'   => $propertyId,
                    'component_key' => $c['key'],
                    'quantity'      => (int) $c['quantity'],
                ]);
            }
        }

        return response()->json(['message' => 'Components saved', 'data' => $saved], 201);
    }

    // ── Property-scoped related data ──────────────────────────────

    public function relatedAssociation(int $id): JsonResponse
    {
        $property = Property::with(['association.manager', 'association.city', 'association.district'])->findOrFail($id);
        return response()->json(['data' => $property->association]);
    }

    public function relatedUnits(Request $request, int $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        $query = $property->units()->with('owners');
        if ($s = $request->query('search')) {
            $query->where(fn ($q) => $q->where('unit_number', 'like', "%{$s}%")->orWhere('building_name', 'like', "%{$s}%"));
        }
        return response()->json(['data' => $query->latest('id')->get()]);
    }

    public function relatedOwners(int $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        $unitIds = $property->units()->pluck('id');
        $ownerIds = \DB::table('unit_owners')->whereIn('unit_id', $unitIds)->pluck('owner_id')->unique();
        $owners = Owner::whereIn('id', $ownerIds)->get();
        return response()->json(['data' => $owners]);
    }

    public function relatedFacilities(int $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        return response()->json(['data' => $property->facilities()->latest('id')->get()]);
    }

    public function relatedMaintenance(int $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        return response()->json(['data' => $property->maintenanceRequests()->with(['unit', 'owner'])->latest('id')->get()]);
    }

    public function relatedContracts(int $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        return response()->json(['data' => $property->contracts()->with(['unit', 'owner'])->latest('id')->get()]);
    }

    public function relatedMeetings(int $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        return response()->json(['data' => $property->meetings()->with('resolutions')->latest('id')->get()]);
    }

    public function relatedLegalCases(int $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        return response()->json(['data' => $property->legalCases()->latest('id')->get()]);
    }

    public function relatedApprovals(int $id): JsonResponse
    {
        $property = Property::findOrFail($id);
        return response()->json(['data' => $property->approvalRequests()->latest('id')->get()]);
    }

    // ── Property Documents ──────────────────────────────────────────

    public function storeDocument(Request $request, int $propertyId): JsonResponse
    {
        Property::findOrFail($propertyId);

        if ($request->hasFile('documents')) {
            $request->validate([
                'documents'   => ['required', 'array', 'min:1'],
                'documents.*' => ['file', 'max:10240'],
            ]);

            $docs = [];
            foreach ($request->file('documents') as $file) {
                $path = $file->store("properties/{$propertyId}/documents", 'public');
                $ext = strtolower($file->getClientOriginalExtension());
                $mime = $file->getClientMimeType();
                $docType = in_array($ext, ['jpg','jpeg','png','gif','webp','svg']) ? 'image' : (in_array($ext, ['pdf']) ? 'pdf' : 'other');
                $docs[] = PropertyDocument::create([
                    'property_id'    => $propertyId,
                    'doc_name'       => $file->getClientOriginalName(),
                    'doc_type'       => $docType,
                    'file_path'      => $path,
                    'mime_type'      => $mime,
                    'file_extension' => $ext,
                    'file_size'      => $file->getSize(),
                    'uploaded_by'    => $request->input('uploaded_by'),
                ]);
            }

            return response()->json(['message' => count($docs) . ' documents uploaded', 'data' => $docs], 201);
        }

        $request->validate([
            'file'     => ['required', 'file', 'max:10240'],
            'doc_name' => ['nullable', 'string', 'max:255'],
            'doc_type' => ['nullable', 'string', 'in:deed,contract,image,blueprint,other'],
        ]);

        $file = $request->file('file');
        $path = $file->store("properties/{$propertyId}/documents", 'public');
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getClientMimeType();
        $autoDocType = in_array($ext, ['jpg','jpeg','png','gif','webp','svg']) ? 'image' : (in_array($ext, ['pdf']) ? 'pdf' : 'other');

        $doc = PropertyDocument::create([
            'property_id'    => $propertyId,
            'doc_name'       => $request->input('doc_name', $file->getClientOriginalName()),
            'doc_type'       => $request->input('doc_type', $autoDocType),
            'file_path'      => $path,
            'mime_type'      => $mime,
            'file_extension' => $ext,
            'file_size'      => $file->getSize(),
            'uploaded_by'    => $request->input('uploaded_by'),
        ]);

        return response()->json(['message' => 'Document uploaded', 'data' => $doc], 201);
    }

    public function destroyDocument(int $propertyId, int $docId): JsonResponse
    {
        Property::findOrFail($propertyId);
        $doc = PropertyDocument::where('property_id', $propertyId)->findOrFail($docId);

        Storage::disk('public')->delete($doc->file_path);
        $doc->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    public function bulkDeleteDocuments(Request $request, int $propertyId): JsonResponse
    {
        Property::findOrFail($propertyId);
        $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);

        $docs = PropertyDocument::where('property_id', $propertyId)->whereIn('id', $request->input('ids'))->get();
        foreach ($docs as $doc) {
            Storage::disk('public')->delete($doc->file_path);
            $doc->delete();
        }

        return response()->json(['message' => 'Documents deleted', 'count' => $docs->count()]);
    }
}
