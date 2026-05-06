<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Facility, Booking, Setting};
use Illuminate\Http\{Request, JsonResponse};
use Carbon\Carbon;

class FacilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Facility::with(['association', 'property']);

        if ($request->filled('association_id')) {
            $q->where('association_id', $request->association_id);
        }
        if ($request->filled('facility_type')) {
            $q->where('facility_type', $request->facility_type);
        }
        if ($request->filled('is_bookable')) {
            $q->where('is_bookable', $request->boolean('is_bookable'));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn($q2) => $q2->where('name', 'LIKE', "%{$s}%")->orWhere('description', 'LIKE', "%{$s}%"));
        }

        $perPage = $request->input('per_page', 15);
        $data = $q->latest('id')->paginate($perPage);

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $facility = Facility::with(['association', 'property', 'bookings.owner'])->findOrFail($id);
        return response()->json(['data' => $facility]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'association_id' => 'required|exists:associations,id',
            'name' => 'required|string|max:255',
            'facility_type' => 'required|string|max:100',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_bookable' => 'boolean',
            'capacity' => 'nullable|integer|min:1',
            'hourly_rate' => 'nullable|numeric|min:0',
            'location_detail' => 'nullable|string|max:255',
            'operating_hours_start' => 'nullable|string|max:5',
            'operating_hours_end' => 'nullable|string|max:5',
            'images' => 'nullable|array',
            'rules' => 'nullable|array',
        ]);

        $facility = Facility::create($request->all());
        return response()->json(['data' => $facility, 'message' => 'created'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $facility = Facility::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'facility_type' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_bookable' => 'boolean',
            'capacity' => 'nullable|integer|min:1',
            'hourly_rate' => 'nullable|numeric|min:0',
            'location_detail' => 'nullable|string|max:255',
            'operating_hours_start' => 'nullable|string|max:5',
            'operating_hours_end' => 'nullable|string|max:5',
            'images' => 'nullable|array',
            'rules' => 'nullable|array',
        ]);

        $facility->update($request->all());
        return response()->json(['data' => $facility, 'message' => 'updated']);
    }

    public function destroy(int $id): JsonResponse
    {
        Facility::findOrFail($id)->delete();
        return response()->json(['message' => 'deleted']);
    }

    public function stats(Request $request): JsonResponse
    {
        $q = Facility::query();
        if ($request->filled('association_id')) {
            $q->where('association_id', $request->association_id);
        }

        $total = $q->count();
        $bookable = (clone $q)->where('is_bookable', true)->count();
        $active = (clone $q)->where('is_active', true)->count();
        $inactive = $total - $active;

        $bookingsToday = Booking::whereDate('starts_at', today())
            ->where('status', 'approved')
            ->when($request->filled('association_id'), fn($b) => $b->where('association_id', $request->association_id))
            ->count();

        return response()->json([
            'total' => $total,
            'bookable' => $bookable,
            'active' => $active,
            'inactive' => $inactive,
            'bookings_today' => $bookingsToday,
        ]);
    }

    public function availability(int $id, Request $request): JsonResponse
    {
        $facility = Facility::findOrFail($id);

        if (!$facility->is_bookable) {
            return response()->json(['message' => 'not_bookable', 'slots' => []], 400);
        }

        $date = $request->input('date', today()->toDateString());
        $start = $facility->operating_hours_start ?: '08:00';
        $end = $facility->operating_hours_end ?: '22:00';

        $existingBookings = Booking::where('facility_id', $id)
            ->whereDate('starts_at', $date)
            ->where('status', 'approved')
            ->get();

        $slots = [];
        $current = Carbon::parse("{$date} {$start}");
        $endTime = Carbon::parse("{$date} {$end}");

        while ($current->lt($endTime)) {
            $slotEnd = $current->copy()->addHour();
            if ($slotEnd->gt($endTime)) break;

            $isBooked = $existingBookings->first(fn($b) =>
                $b->starts_at->lt($slotEnd) && $b->ends_at->gt($current)
            );

            $slots[] = [
                'start' => $current->format('H:i'),
                'end' => $slotEnd->format('H:i'),
                'available' => !$isBooked,
                'booking' => $isBooked ? [
                    'id' => $isBooked->id,
                    'owner_id' => $isBooked->owner_id,
                    'owner_name' => $isBooked->owner?->full_name,
                ] : null,
            ];

            $current = $slotEnd;
        }

        return response()->json([
            'facility' => $facility,
            'date' => $date,
            'slots' => $slots,
        ]);
    }

    public function book(int $id, Request $request): JsonResponse
    {
        $facility = Facility::findOrFail($id);

        if (!$facility->is_bookable) {
            return response()->json(['message' => 'facility_not_bookable'], 400);
        }

        $request->validate([
            'owner_id' => 'nullable|exists:owners,id',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $date = $request->date;
        $startTime = $request->start_time;
        $startsAt = Carbon::parse("{$date} {$startTime}");
        $endsAt = $startsAt->copy()->addHour();

        if ($request->filled('owner_id')) {
            $alreadyBooked = Booking::where('facility_id', $id)
                ->where('owner_id', $request->owner_id)
                ->whereDate('starts_at', $date)
                ->where('status', 'approved')
                ->exists();

            if ($alreadyBooked) {
                return response()->json(['message' => 'owner_already_booked_today'], 422);
            }
        }

        $conflict = Booking::where('facility_id', $id)
            ->whereDate('starts_at', $date)
            ->where('status', 'approved')
            ->where(fn($q) => $q->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt))
            ->exists();

        if ($conflict) {
            return response()->json(['message' => 'slot_already_booked'], 422);
        }

        $booking = Booking::create([
            'facility_id' => $id,
            'association_id' => $facility->association_id,
            'owner_id' => $request->owner_id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'approved',
            'booked_by' => $request->owner_id ? 'owner' : 'admin',
            'notes' => $request->notes,
        ]);

        $booking->load('owner', 'facility');

        return response()->json(['data' => $booking, 'message' => 'booked'], 201);
    }

    public function cancelBooking(int $bookingId): JsonResponse
    {
        $booking = Booking::findOrFail($bookingId);
        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
        return response()->json(['message' => 'cancelled']);
    }

    public function bookings(int $id, Request $request): JsonResponse
    {
        $q = Booking::with('owner')
            ->where('facility_id', $id)
            ->orderByDesc('starts_at');

        if ($request->filled('date')) {
            $q->whereDate('starts_at', $request->date);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        return response()->json(['data' => $q->limit(100)->get()]);
    }

    public function associationBookings(int $associationId, Request $request): JsonResponse
    {
        $q = Booking::with(['facility', 'owner'])
            ->where('association_id', $associationId)
            ->orderByDesc('starts_at');

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        $perPage = $request->input('per_page', 15);
        $data = $q->paginate($perPage);

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ],
        ]);
    }

    public function bookingStats(Request $request): JsonResponse
    {
        $q = Booking::query();
        if ($request->filled('association_id')) $q->where('association_id', $request->association_id);

        $total = $q->count();
        $approved = (clone $q)->where('status', 'approved')->count();
        $cancelled = (clone $q)->where('status', 'cancelled')->count();
        $today = (clone $q)->whereDate('starts_at', today())->where('status', 'approved')->count();

        return response()->json(compact('total', 'approved', 'cancelled', 'today'));
    }

    public function allBookings(Request $request): JsonResponse
    {
        $q = Booking::with(['facility', 'owner', 'association'])
            ->orderByDesc('starts_at');

        if ($request->filled('association_id')) {
            $q->where('association_id', $request->association_id);
        }
        if ($request->filled('facility_id')) {
            $q->where('facility_id', $request->facility_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($q2) use ($s) {
                $q2->whereHas('owner', fn($o) => $o->where('full_name', 'LIKE', "%{$s}%"))
                   ->orWhereHas('facility', fn($f) => $f->where('name', 'LIKE', "%{$s}%"));
            });
        }

        $perPage = $request->input('per_page', 15);
        $data = $q->paginate($perPage);

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ],
        ]);
    }
}
