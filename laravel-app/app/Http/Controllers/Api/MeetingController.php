<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Meeting;
use App\Models\Owner;
use App\Models\Resolution;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeetingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Meeting::with(['association', 'property', 'resolutions']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('meeting_number', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        if ($v = $request->query('status'))          $query->where('status', $v);
        if ($v = $request->query('type'))             $query->where('type', $v);
        if ($v = $request->query('association_id'))    $query->where('association_id', $v);
        if ($v = $request->query('property_id'))       $query->where('property_id', $v);
        if ($v = $request->query('attendance_type'))   $query->where('attendance_type', $v);
        if ($request->has('is_remote'))                $query->where('is_remote', filter_var($request->query('is_remote'), FILTER_VALIDATE_BOOLEAN));

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
            'total'      => Meeting::count(),
            'scheduled'  => Meeting::where('status', 'scheduled')->count(),
            'completed'  => Meeting::where('status', 'completed')->count(),
            'cancelled'  => Meeting::where('status', 'cancelled')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $meeting = Meeting::with(['association', 'property', 'resolutions', 'votes.phases'])->findOrFail($id);

        $inviteeIds = $meeting->invitees ?? [];
        $inviteeOwners = [];
        if (count($inviteeIds)) {
            $inviteeOwners = Owner::whereIn('id', $inviteeIds)
                ->select('id', 'full_name', 'national_id', 'phone', 'email')
                ->get()
                ->toArray();
        }

        $data = $meeting->toArray();
        $data['invitee_details'] = $inviteeOwners;

        $attendees = $meeting->attendees ?? [];
        $attendeeMap = collect($attendees)->keyBy('owner_id');
        foreach ($inviteeOwners as &$owner) {
            $att = $attendeeMap->get($owner['id']);
            $owner['attendance_status'] = $att ? ($att['status'] ?? 'absent') : 'pending';
        }
        $data['invitee_details'] = $inviteeOwners;

        return response()->json(['data' => $data]);
    }

    public function updateAttendance(Request $request, int $id): JsonResponse
    {
        $meeting = Meeting::findOrFail($id);

        $data = $request->validate([
            'attendees'            => ['required', 'array'],
            'attendees.*.owner_id' => ['required', 'integer', 'exists:owners,id'],
            'attendees.*.status'   => ['required', 'string', 'in:present,absent,excused'],
        ]);

        $meeting->update(['attendees' => $data['attendees']]);
        ActivityLog::record('meeting', $meeting->id, 'attendance', 'تم تحديث كشف الحضور — ' . $meeting->title);

        return response()->json([
            'message' => 'تم تحديث كشف الحضور بنجاح',
            'data'    => $meeting->fresh(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            $this->storeRules(),
            $this->arMessages(),
        );

        $data['meeting_number'] = 'MTG-' . date('Y') . '-' . str_pad((Meeting::max('id') ?? 0) + 1, 5, '0', STR_PAD_LEFT);
        $data['status'] = $data['status'] ?? 'scheduled';

        $data['invitees'] = $this->resolveInvitees($data);

        $meeting = Meeting::create($data);
        ActivityLog::record('meeting', $meeting->id, 'created', 'تم إنشاء اجتماع — ' . $meeting->title);

        Notifier::dispatch('meeting.scheduled', [
            'subject' => $meeting,
            'data' => [
                'title'        => $meeting->title,
                'scheduled_at' => optional($meeting->scheduled_at)->format('Y-m-d H:i'),
                'location'     => $meeting->location,
                'meeting_number' => $meeting->meeting_number,
            ],
        ]);

        return response()->json(['message' => 'تم إنشاء الاجتماع بنجاح', 'data' => $meeting->load(['association', 'property'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $meeting = Meeting::findOrFail($id);

        $data = $request->validate(
            $this->updateRules(),
            $this->arMessages(),
        );

        if (isset($data['attendance_type'])) {
            $merged = array_merge($meeting->toArray(), $data);
            $data['invitees'] = $this->resolveInvitees($merged);
        }

        $meeting->update($data);
        ActivityLog::record('meeting', $meeting->id, 'updated', 'تم تحديث اجتماع — ' . $meeting->title);

        return response()->json(['message' => 'تم تحديث الاجتماع بنجاح', 'data' => $meeting->fresh()->load(['association', 'property'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        $meeting = Meeting::findOrFail($id);
        ActivityLog::record('meeting', $meeting->id, 'deleted', 'تم حذف اجتماع — ' . $meeting->title);
        $meeting->delete();
        return response()->json(['message' => 'تم حذف الاجتماع بنجاح']);
    }

    public function invite(Request $request, int $id): JsonResponse
    {
        $meeting = Meeting::findOrFail($id);

        $data = $request->validate([
            'owner_ids'   => ['required', 'array', 'min:1'],
            'owner_ids.*' => ['integer', 'exists:owners,id'],
        ], [
            'owner_ids.required' => 'قائمة المدعوين مطلوبة',
            'owner_ids.min'      => 'يجب إضافة مدعو واحد على الأقل',
        ]);

        $current = $meeting->invitees ?? [];
        $merged  = array_values(array_unique(array_merge($current, $data['owner_ids'])));

        $meeting->update(['invitees' => $merged]);
        ActivityLog::record('meeting', $meeting->id, 'invited', 'تم تحديث قائمة المدعوين — ' . $meeting->title);

        return response()->json([
            'message' => 'تم تحديث قائمة المدعوين بنجاح',
            'data'    => $meeting->fresh()->load(['association', 'property']),
        ]);
    }

    // ── Resolutions CRUD ────────────────────────────────────────────
    public function resolutions(int $meetingId): JsonResponse
    {
        $resolutions = Resolution::where('meeting_id', $meetingId)->latest('id')->get();
        return response()->json(['data' => $resolutions]);
    }

    public function storeResolution(Request $request, int $meetingId): JsonResponse
    {
        $data = $request->validate([
            'title'           => ['required', 'string', 'max:255'],
            'resolution_type' => ['nullable', 'string', 'max:100'],
            'description'     => ['nullable', 'string'],
            'yes_votes'       => ['nullable', 'integer', 'min:0'],
            'no_votes'        => ['nullable', 'integer', 'min:0'],
            'abstain_votes'   => ['nullable', 'integer', 'min:0'],
            'status'          => ['nullable', 'string', 'max:50'],
        ]);

        $data['meeting_id'] = $meetingId;
        $data['resolution_number'] = 'RES-' . date('Y') . '-' . str_pad((Resolution::max('id') ?? 0) + 1, 5, '0', STR_PAD_LEFT);
        $data['status'] = $data['status'] ?? 'pending';

        $resolution = Resolution::create($data);
        return response()->json(['message' => 'تم إضافة القرار بنجاح', 'data' => $resolution], 201);
    }

    public function updateResolution(Request $request, int $meetingId, int $resolutionId): JsonResponse
    {
        $resolution = Resolution::where('meeting_id', $meetingId)->findOrFail($resolutionId);

        $data = $request->validate([
            'title'           => ['sometimes', 'string', 'max:255'],
            'resolution_type' => ['nullable', 'string', 'max:100'],
            'description'     => ['nullable', 'string'],
            'yes_votes'       => ['nullable', 'integer', 'min:0'],
            'no_votes'        => ['nullable', 'integer', 'min:0'],
            'abstain_votes'   => ['nullable', 'integer', 'min:0'],
            'status'          => ['nullable', 'string', 'max:50'],
        ]);

        $resolution->update($data);
        return response()->json(['message' => 'تم تحديث القرار بنجاح', 'data' => $resolution->fresh()]);
    }

    public function destroyResolution(int $meetingId, int $resolutionId): JsonResponse
    {
        $resolution = Resolution::where('meeting_id', $meetingId)->findOrFail($resolutionId);
        $resolution->delete();
        return response()->json(['message' => 'تم حذف القرار بنجاح']);
    }

    // ── Private helpers ─────────────────────────────────────────────

    private function storeRules(): array
    {
        return [
            'title'               => ['required', 'string', 'max:255'],
            'type'                => ['nullable', 'string', 'max:100'],
            'association_id'      => ['nullable', 'exists:associations,id'],
            'property_id'         => ['nullable', 'exists:properties,id'],
            'scheduled_at'        => ['required', 'date'],
            'agenda'              => ['nullable', 'string'],
            'agenda_items'        => ['nullable', 'array'],
            'agenda_items.*.title' => ['required_with:agenda_items', 'string', 'max:255'],
            'agenda_items.*.time' => ['required_with:agenda_items', 'string', 'max:50'],
            'location'            => ['nullable', 'string', 'max:255'],
            'minutes'             => ['nullable', 'string'],
            'status'              => ['nullable', 'string', 'max:50'],
            'notes'               => ['nullable', 'string'],
            'attendance_type'     => ['nullable', Rule::in(['all_association', 'specific_property', 'selected_owners'])],
            'attendance_scope_id' => ['nullable', 'integer', 'exists:properties,id'],
            'is_remote'           => ['nullable', 'boolean'],
            'remote_platform'     => ['nullable', 'required_if:is_remote,true', Rule::in(['teams', 'google_meet', 'zoom', 'manual'])],
            'remote_link'         => ['nullable', 'required_if:remote_platform,manual', 'string', 'max:500'],
            'invitees'            => ['nullable', 'array'],
            'invitees.*'          => ['integer', 'exists:owners,id'],
            'manager_name'        => ['nullable', 'string', 'max:255'],
            'manager_id'          => ['nullable', 'exists:users,id'],
        ];
    }

    private function updateRules(): array
    {
        $rules = $this->storeRules();
        $rules['title']        = ['sometimes', 'string', 'max:255'];
        $rules['scheduled_at'] = ['sometimes', 'date'];
        return $rules;
    }

    private function arMessages(): array
    {
        return [
            'title.required'             => 'عنوان الاجتماع مطلوب',
            'scheduled_at.required'      => 'تاريخ ووقت الاجتماع مطلوب',
            'attendance_type.in'         => 'نوع الحضور غير صالح',
            'attendance_scope_id.exists' => 'العقار المحدد غير موجود',
            'remote_platform.in'         => 'منصة الاجتماع عن بُعد غير صالحة',
            'remote_platform.required_if' => 'منصة الاجتماع مطلوبة عند تفعيل الاجتماع عن بُعد',
            'remote_link.required_if'    => 'رابط الاجتماع مطلوب عند اختيار منصة يدوية',
            'invitees.*.exists'          => 'أحد المدعوين غير موجود في النظام',
            'agenda_items.*.title.required_with' => 'عنوان بند جدول الأعمال مطلوب',
            'agenda_items.*.time.required_with'  => 'وقت بند جدول الأعمال مطلوب',
        ];
    }

    private function resolveInvitees(array $data): array
    {
        $type = $data['attendance_type'] ?? null;

        if ($type === 'all_association') {
            $associationId = $data['association_id'] ?? null;
            if (! $associationId) {
                return $data['invitees'] ?? [];
            }
            return Owner::whereHas('units.property', fn ($q) => $q->where('association_id', $associationId))
                ->pluck('id')
                ->unique()
                ->values()
                ->toArray();
        }

        if ($type === 'specific_property') {
            $propertyId = $data['attendance_scope_id'] ?? $data['property_id'] ?? null;
            if (! $propertyId) {
                return $data['invitees'] ?? [];
            }
            return Owner::whereHas('units', fn ($q) => $q->where('property_id', $propertyId))
                ->pluck('id')
                ->unique()
                ->values()
                ->toArray();
        }

        // 'selected_owners' or null — use provided array
        return $data['invitees'] ?? [];
    }
}
