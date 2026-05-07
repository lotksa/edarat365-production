<?php

namespace App\Http\Controllers\Api;

use App\Models\ApprovalRequest;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\MaintenanceRequest;
use App\Models\Meeting;
use App\Models\Owner;
use App\Models\Resolution;
use App\Models\Unit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ResourceController extends Controller
{
    private array $modelMap = [
        'owners' => Owner::class,
        'units' => Unit::class,
        'invoices' => Invoice::class,
        'facilities' => Facility::class,
        'bookings' => Booking::class,
        'contracts' => Contract::class,
        'maintenance-requests' => MaintenanceRequest::class,
        'meetings' => Meeting::class,
        'resolutions' => Resolution::class,
        'legal-cases' => LegalCase::class,
        'approval-requests' => ApprovalRequest::class,
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        $modelClass = $this->resolveModel($resource);
        $perPage = (int) $request->query('per_page', 15);
        $records = $modelClass::query()->latest('id')->paginate($perPage);

        return response()->json([
            'resource' => $resource,
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $modelClass = $this->resolveModel($resource);
        $payload = $request->validate($this->rules($resource));
        $record = $modelClass::create($payload);

        return response()->json([
            'resource' => $resource,
            'message' => 'Created',
            'data' => $record,
        ], 201);
    }

    public function show(string $resource, int $id): JsonResponse
    {
        $modelClass = $this->resolveModel($resource);
        $record = $modelClass::query()->findOrFail($id);

        return response()->json([
            'resource' => $resource,
            'data' => $record,
        ]);
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        $modelClass = $this->resolveModel($resource);
        $payload = $request->validate($this->rules($resource, $id));
        $record = $modelClass::query()->findOrFail($id);
        $record->update($payload);

        return response()->json([
            'resource' => $resource,
            'message' => 'Updated',
            'data' => $record->fresh(),
        ]);
    }

    public function destroy(string $resource, int $id): JsonResponse
    {
        $modelClass = $this->resolveModel($resource);
        $record = $modelClass::query()->findOrFail($id);
        $record->delete();

        return response()->json([
            'resource' => $resource,
            'message' => 'Deleted',
        ]);
    }

    private function resolveModel(string $resource): string
    {
        if (! array_key_exists($resource, $this->modelMap)) {
            throw ValidationException::withMessages([
                'resource' => 'Resource is not supported.',
            ]);
        }

        return $this->modelMap[$resource];
    }

    private function rules(string $resource, ?int $id = null): array
    {
        return match ($resource) {
            'owners' => [
                'user_id' => ['nullable', 'exists:users,id'],
                'national_id' => ['required', 'string', 'size:10', 'regex:/^\d{10}$/', new \App\Rules\UniqueEncrypted('owners', 'national_id_hash', ignoreId: $id ? (int) $id : null)],
                'full_name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/'],
                'email' => ['nullable', 'email', 'max:255'],
            ],
            'units' => [
                'unit_number' => ['required', 'string', 'max:100', 'unique:units,unit_number,' . $id],
                'building_name' => ['nullable', 'string', 'max:255'],
                'ownership_ratio' => ['required', 'numeric', 'min:0', 'max:100'],
                'owner_id' => ['nullable', 'exists:owners,id'],
            ],
            'invoices' => [
                'owner_id' => ['nullable', 'exists:owners,id'],
                'unit_id' => ['nullable', 'exists:units,id'],
                'amount' => ['required', 'numeric', 'min:0'],
                'due_date' => ['required', 'date'],
                'status' => ['required', 'string', 'max:50'],
            ],
            'facilities' => [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'is_active' => ['required', 'boolean'],
            ],
            'bookings' => [
                'facility_id' => ['required', 'exists:facilities,id'],
                'owner_id' => ['nullable', 'exists:owners,id'],
                'starts_at' => ['required', 'date'],
                'ends_at' => ['required', 'date', 'after:starts_at'],
                'status' => ['required', 'string', 'max:50'],
            ],
            'contracts' => [
                'owner_id' => ['required', 'exists:owners,id'],
                'unit_id' => ['required', 'exists:units,id'],
                'tenant_name' => ['required', 'string', 'max:255'],
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after:start_date'],
                'status' => ['required', 'string', 'max:50'],
            ],
            'maintenance-requests' => [
                'owner_id' => ['nullable', 'exists:owners,id'],
                'unit_id' => ['nullable', 'exists:units,id'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'priority' => ['required', 'string', 'max:50'],
                'status' => ['required', 'string', 'max:50'],
            ],
            'meetings' => [
                'title' => ['required', 'string', 'max:255'],
                'scheduled_at' => ['required', 'date'],
                'type' => ['required', 'string', 'max:100'],
                'agenda' => ['nullable', 'string'],
            ],
            'resolutions' => [
                'meeting_id' => ['required', 'exists:meetings,id'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'yes_votes' => ['nullable', 'integer', 'min:0'],
                'no_votes' => ['nullable', 'integer', 'min:0'],
                'abstain_votes' => ['nullable', 'integer', 'min:0'],
            ],
            'legal-cases' => [
                'case_number' => ['required', 'string', 'max:100', 'unique:legal_cases,case_number,' . $id],
                'title' => ['required', 'string', 'max:255'],
                'status' => ['required', 'string', 'max:50'],
                'hearing_date' => ['nullable', 'date'],
            ],
            'approval-requests' => [
                'request_type' => ['required', 'string', 'max:100'],
                'status' => ['required', 'string', 'max:50'],
                'requested_by' => ['nullable', 'exists:users,id'],
                'notes' => ['nullable', 'string'],
            ],
            default => [],
        };
    }
}
