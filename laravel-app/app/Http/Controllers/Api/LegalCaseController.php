<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CaseUpdate;
use App\Models\LegalCase;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LegalCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LegalCase::with(['association', 'property', 'owner', 'unit.property']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('case_number', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('plaintiff', 'like', "%{$search}%")
                  ->orWhere('defendant', 'like', "%{$search}%")
                  ->orWhere('lawyer_name', 'like', "%{$search}%");
            });
        }

        if ($v = $request->query('status'))          $query->where('status', $v);
        if ($v = $request->query('case_type'))        $query->where('case_type', $v);
        if ($v = $request->query('priority'))         $query->where('priority', $v);
        if ($v = $request->query('association_id')) {
            $query->where(function ($q) use ($v) {
                $q->where('association_id', $v)
                  ->orWhereHas('property', fn ($pq) => $pq->where('association_id', $v))
                  ->orWhereHas('unit.property', fn ($uq) => $uq->where('association_id', $v));
            });
        }
        if ($v = $request->query('property_id'))       $query->where('property_id', $v);
        if ($v = $request->query('owner_id'))          $query->where('owner_id', $v);
        if ($v = $request->query('unit_id'))           $query->where('unit_id', $v);

        $perPage = (int) $request->query('per_page', 15);
        $records = $query->latest('id')->paginate($perPage);

        $items = collect($records->items())->map(function ($case) {
            $arr = $case->toArray();
            $arr['owner_name'] = $case->owner?->full_name ?? '-';
            $arr['property_name'] = $case->property?->name ?? $case->unit?->property?->name ?? '-';
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

    public function stats(): JsonResponse
    {
        return response()->json([
            'total'    => LegalCase::count(),
            'open'     => LegalCase::where('status', 'open')->count(),
            'pending'  => LegalCase::where('status', 'pending')->count(),
            'closed'   => LegalCase::where('status', 'closed')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $case = LegalCase::with(['association', 'property', 'owner', 'unit.property', 'updates.creator'])->findOrFail($id);
        return response()->json(['data' => $case]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'          => ['required', 'string', 'max:255'],
            'case_type'      => ['nullable', 'string', 'max:100'],
            'association_id' => ['nullable', 'exists:associations,id'],
            'property_id'    => ['nullable', 'exists:properties,id'],
            'owner_id'       => ['nullable', 'exists:owners,id'],
            'unit_id'        => ['nullable', 'exists:units,id'],
            'court_name'     => ['nullable', 'string', 'max:255'],
            'court_type'     => ['nullable', 'string', 'max:100'],
            'plaintiff'      => ['nullable', 'string', 'max:255'],
            'defendant'      => ['nullable', 'string', 'max:255'],
            'lawyer_name'    => ['nullable', 'string', 'max:255'],
            'filing_date'    => ['nullable', 'date'],
            'hearing_date'   => ['nullable', 'date'],
            'priority'       => ['nullable', 'string', 'max:50'],
            'description'    => ['nullable', 'string'],
            'verdict'        => ['nullable', 'string'],
            'amount'         => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string'],
            'documents'      => ['nullable', 'array'],
            'status'         => ['nullable', 'string', 'max:50'],
        ], [
            'title.required' => 'عنوان القضية مطلوب',
        ]);

        $data = $this->normalizeAssociationScope($data);
        $data['case_number'] = 'CASE-' . date('Y') . '-' . str_pad((LegalCase::max('id') ?? 0) + 1, 5, '0', STR_PAD_LEFT);
        $data['status'] = $data['status'] ?? 'open';

        $case = LegalCase::create($data);
        ActivityLog::record('legal_case', $case->id, 'created', 'تم إنشاء قضية — ' . $case->title);

        Notifier::dispatch('legal_case.created', [
            'subject' => $case,
            'data'    => [
                'number' => $case->case_number,
                'title'  => $case->title,
                'status' => $case->status,
            ],
        ]);

        return response()->json(['message' => 'تم إنشاء القضية بنجاح', 'data' => $case->load(['association', 'property', 'owner', 'unit'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $case = LegalCase::findOrFail($id);

        $data = $request->validate([
            'title'          => ['sometimes', 'string', 'max:255'],
            'case_type'      => ['nullable', 'string', 'max:100'],
            'association_id' => ['nullable', 'exists:associations,id'],
            'property_id'    => ['nullable', 'exists:properties,id'],
            'owner_id'       => ['nullable', 'exists:owners,id'],
            'unit_id'        => ['nullable', 'exists:units,id'],
            'court_name'     => ['nullable', 'string', 'max:255'],
            'court_type'     => ['nullable', 'string', 'max:100'],
            'plaintiff'      => ['nullable', 'string', 'max:255'],
            'defendant'      => ['nullable', 'string', 'max:255'],
            'lawyer_name'    => ['nullable', 'string', 'max:255'],
            'filing_date'    => ['nullable', 'date'],
            'hearing_date'   => ['nullable', 'date'],
            'priority'       => ['nullable', 'string', 'max:50'],
            'description'    => ['nullable', 'string'],
            'verdict'        => ['nullable', 'string'],
            'amount'         => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string'],
            'documents'      => ['nullable', 'array'],
            'status'         => ['nullable', 'string', 'max:50'],
        ]);

        $data = $this->normalizeAssociationScope($data, $case);
        $oldStatus = $case->status;
        $case->update($data);
        ActivityLog::record('legal_case', $case->id, 'updated', 'تم تحديث قضية — ' . $case->title);

        if (isset($data['status']) && $data['status'] !== $oldStatus) {
            Notifier::dispatch('legal_case.status_changed', [
                'subject' => $case,
                'data'    => [
                    'number' => $case->case_number,
                    'status' => $case->status,
                    'title'  => $case->title,
                ],
            ]);
        }

        return response()->json(['message' => 'تم تحديث القضية بنجاح', 'data' => $case->fresh()->load(['association', 'property', 'owner', 'unit'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        $case = LegalCase::findOrFail($id);
        ActivityLog::record('legal_case', $case->id, 'deleted', 'تم حذف قضية — ' . $case->title);
        $case->delete();
        return response()->json(['message' => 'تم حذف القضية بنجاح']);
    }

    public function updates(int $caseId): JsonResponse
    {
        $updates = CaseUpdate::where('legal_case_id', $caseId)
            ->with('creator')
            ->latest('id')
            ->get();
        return response()->json(['data' => $updates]);
    }

    public function storeUpdate(Request $request, int $caseId): JsonResponse
    {
        $case = LegalCase::findOrFail($caseId);

        $data = $request->validate([
            'title'          => ['required', 'string', 'max:255'],
            'verdict_status' => ['nullable', 'string', 'max:100'],
            'reminder_date'  => ['nullable', 'date'],
            'details'        => ['nullable', 'string'],
            'documents'      => ['nullable', 'array'],
        ], [
            'title.required' => 'عنوان التحديث مطلوب',
        ]);

        $data['legal_case_id'] = $caseId;
        $data['created_by'] = auth()->id() ?? null;

        $update = CaseUpdate::create($data);
        ActivityLog::record('legal_case', $caseId, 'update_added', 'تم إضافة تحديث للقضية — ' . $update->title);

        Notifier::dispatch('legal_case.update_added', [
            'subject' => $case,
            'data'    => [
                'number' => $case->case_number,
                'title'  => $update->title,
            ],
        ]);

        return response()->json([
            'message' => 'تم إضافة التحديث بنجاح',
            'data' => $update->load('creator'),
        ], 201);
    }

    public function updateUpdate(Request $request, int $caseId, int $updateId): JsonResponse
    {
        $update = CaseUpdate::where('legal_case_id', $caseId)->findOrFail($updateId);

        $data = $request->validate([
            'title'          => ['sometimes', 'string', 'max:255'],
            'verdict_status' => ['nullable', 'string', 'max:100'],
            'reminder_date'  => ['nullable', 'date'],
            'details'        => ['nullable', 'string'],
            'documents'      => ['nullable', 'array'],
        ]);

        $update->update($data);
        return response()->json(['message' => 'تم تحديث البيانات بنجاح', 'data' => $update->fresh()->load('creator')]);
    }

    public function destroyUpdate(int $caseId, int $updateId): JsonResponse
    {
        $update = CaseUpdate::where('legal_case_id', $caseId)->findOrFail($updateId);
        $update->delete();
        return response()->json(['message' => 'تم حذف التحديث بنجاح']);
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        // SECURITY: strict mime whitelist (blocks SVG XSS, HTML, scripts, executables).
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif,webp',
            ],
        ]);

        $file = $request->file('file');
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $file->getClientOriginalExtension()));
        // Non-guessable file name; blocks IDOR-style enumeration.
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $path = $file->storeAs('legal-cases/documents', $safeName, 'public');

        return response()->json([
            'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'path' => '/storage/' . $path,
            'size' => $file->getSize(),
        ]);
    }

    private function normalizeAssociationScope(array $data, ?LegalCase $case = null): array
    {
        $associationId = $this->nullableInt($data['association_id'] ?? $case?->association_id);
        $propertyId = $this->nullableInt(array_key_exists('property_id', $data) ? $data['property_id'] : $case?->property_id);
        $unitId = $this->nullableInt(array_key_exists('unit_id', $data) ? $data['unit_id'] : $case?->unit_id);
        $ownerId = $this->nullableInt(array_key_exists('owner_id', $data) ? $data['owner_id'] : $case?->owner_id);

        if ($propertyId) {
            $propertyAssociationId = $this->nullableInt(Property::whereKey($propertyId)->value('association_id'));
            if ($associationId && $propertyAssociationId && $propertyAssociationId !== $associationId) {
                throw ValidationException::withMessages([
                    'property_id' => ['العقار المحدد لا يتبع الجمعية الحالية.'],
                ]);
            }
            $associationId = $associationId ?: $propertyAssociationId;
        }

        if ($unitId) {
            $unit = Unit::with('property:id,association_id')->find($unitId);
            $unitAssociationId = $this->nullableInt($unit?->property?->association_id);
            if ($propertyId && $unit && (int) $unit->property_id !== $propertyId) {
                throw ValidationException::withMessages([
                    'unit_id' => ['الوحدة المحددة لا تتبع العقار الحالي.'],
                ]);
            }
            if ($associationId && $unitAssociationId && $unitAssociationId !== $associationId) {
                throw ValidationException::withMessages([
                    'unit_id' => ['الوحدة المحددة لا تتبع الجمعية الحالية.'],
                ]);
            }
            $associationId = $associationId ?: $unitAssociationId;
        }

        if ($associationId && $ownerId && ! $this->ownerBelongsToAssociation($ownerId, $associationId)) {
            throw ValidationException::withMessages([
                'owner_id' => ['المالك المحدد لا يتبع الجمعية الحالية.'],
            ]);
        }

        $data['association_id'] = $associationId;
        if (array_key_exists('property_id', $data)) {
            $data['property_id'] = $propertyId;
        }
        if (array_key_exists('unit_id', $data)) {
            $data['unit_id'] = $unitId;
        }
        if (array_key_exists('owner_id', $data)) {
            $data['owner_id'] = $ownerId;
        }

        return $data;
    }

    private function ownerBelongsToAssociation(int $ownerId, int $associationId): bool
    {
        return Owner::whereKey($ownerId)
            ->where(function ($q) use ($associationId) {
                $q->whereHas('properties', fn ($pq) => $pq->where('association_id', $associationId))
                  ->orWhereHas('units.property', fn ($uq) => $uq->where('association_id', $associationId));
            })
            ->exists();
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
