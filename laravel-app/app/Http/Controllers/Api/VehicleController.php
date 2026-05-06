<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Vehicle::with(['association', 'property', 'owner', 'parkingSpot']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('plate_number', 'like', "%{$search}%")
                  ->orWhere('driver_name', 'like', "%{$search}%");
            });
        }
        if ($v = $request->query('association_id')) $query->where('association_id', $v);
        if ($v = $request->query('property_id')) $query->where('property_id', $v);
        if ($v = $request->query('parking_type')) $query->where('parking_type', $v);
        if ($v = $request->query('vehicle_type')) $query->where('car_type', $v);
        if ($v = $request->query('status')) $query->where('status', $v);

        $perPage = (int) $request->query('per_page', 15);
        $records = $query->latest('id')->paginate($perPage);

        $items = collect($records->items())->map(function ($v) {
            $arr = $v->toArray();
            $arr['association_name'] = $v->association?->name ?? '-';
            $arr['property_name'] = $v->property?->name ?? '-';
            $arr['owner_name'] = $v->owner?->full_name ?? '-';
            $arr['parking_number'] = $v->parkingSpot?->parking_number ?? '-';
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
            'total'    => Vehicle::count(),
            'active'   => Vehicle::where('status', 'active')->count(),
            'inactive' => Vehicle::where('status', '!=', 'active')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $vehicle = Vehicle::with(['association', 'property', 'owner', 'parkingSpot'])->findOrFail($id);
        return response()->json(['data' => $vehicle]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'association_id'   => ['nullable', 'exists:associations,id'],
            'property_id'     => ['nullable', 'exists:properties,id'],
            'owner_id'        => ['nullable', 'exists:owners,id'],
            'parking_spot_id' => ['nullable', 'exists:parking_spots,id'],
            'parking_type'    => ['nullable', 'string', 'max:100'],
            'car_type'        => ['nullable', 'string', 'max:100'],
            'car_model'       => ['nullable', 'string', 'max:100'],
            'car_color'       => ['nullable', 'string', 'max:100'],
            'plate_number'    => ['nullable', 'string', 'max:100'],
            'driver_name'     => ['nullable', 'string', 'max:255'],
        ], []);

        $vehicle = Vehicle::create($data);
        ActivityLog::record('vehicle', $vehicle->id, 'created', 'تم إنشاء سيارة جديدة — ' . ($vehicle->plate_number ?? ''));

        return response()->json([
            'message' => 'تم إنشاء السيارة بنجاح',
            'data' => $vehicle->load(['association', 'property', 'owner', 'parkingSpot']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        $data = $request->validate([
            'association_id'   => ['nullable', 'exists:associations,id'],
            'property_id'     => ['nullable', 'exists:properties,id'],
            'owner_id'        => ['nullable', 'exists:owners,id'],
            'parking_spot_id' => ['nullable', 'exists:parking_spots,id'],
            'parking_type'    => ['nullable', 'string', 'max:100'],
            'car_type'        => ['nullable', 'string', 'max:100'],
            'car_model'       => ['nullable', 'string', 'max:100'],
            'car_color'       => ['nullable', 'string', 'max:100'],
            'plate_number'    => ['nullable', 'string', 'max:100'],
            'driver_name'     => ['nullable', 'string', 'max:255'],
        ]);

        $vehicle->update($data);
        ActivityLog::record('vehicle', $vehicle->id, 'updated', 'تم تعديل السيارة — ' . ($vehicle->plate_number ?? ''));

        return response()->json([
            'message' => 'تم تعديل السيارة بنجاح',
            'data' => $vehicle->fresh()->load(['association', 'property', 'owner', 'parkingSpot']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $vehicle->delete();
        ActivityLog::record('vehicle', $id, 'deleted', 'تم حذف السيارة');

        return response()->json(['message' => 'تم حذف السيارة بنجاح']);
    }
}
