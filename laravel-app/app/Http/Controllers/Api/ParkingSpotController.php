<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ParkingSpot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParkingSpotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ParkingSpot::with(['association', 'property']);

        if ($search = $request->query('search')) {
            $query->where('parking_number', 'like', "%{$search}%");
        }
        if ($v = $request->query('association_id')) $query->where('association_id', $v);
        if ($v = $request->query('property_id')) $query->where('property_id', $v);
        if ($v = $request->query('parking_type')) $query->where('parking_type', $v);
        if ($v = $request->query('spot_type')) $query->where('parking_type', $v);
        if ($v = $request->query('status')) {
            if ($v === 'occupied') {
                $query->whereHas('vehicles');
            } elseif ($v === 'available') {
                $query->whereDoesntHave('vehicles');
            } else {
                $query->where('status', $v);
            }
        }

        $perPage = (int) $request->query('per_page', 15);
        $records = $query->latest('id')->paginate($perPage);

        $items = collect($records->items())->map(function ($spot) {
            $arr = $spot->toArray();
            $arr['association_name'] = $spot->association?->name ?? '-';
            $arr['property_name'] = $spot->property?->name ?? '-';
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
        $total = ParkingSpot::count();
        $occupied = ParkingSpot::whereHas('vehicles')->count();

        return response()->json([
            'total'     => $total,
            'occupied'  => $occupied,
            'available' => $total - $occupied,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $spot = ParkingSpot::with(['association', 'property', 'vehicles.owner'])->findOrFail($id);
        return response()->json(['data' => $spot]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'association_id' => ['nullable', 'exists:associations,id'],
            'property_id'    => ['nullable', 'exists:properties,id'],
            'parking_type'   => ['nullable', 'string', 'max:100'],
            'parking_number' => ['required', 'string', 'max:100'],
        ], [
            'parking_number.required' => 'رقم الموقف مطلوب',
        ]);

        $spot = ParkingSpot::create($data);
        ActivityLog::record('parking_spot', $spot->id, 'created', 'تم إنشاء موقف جديد — ' . $spot->parking_number);

        return response()->json([
            'message' => 'تم إنشاء الموقف بنجاح',
            'data' => $spot->load(['association', 'property']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $spot = ParkingSpot::findOrFail($id);

        $data = $request->validate([
            'association_id' => ['nullable', 'exists:associations,id'],
            'property_id'    => ['nullable', 'exists:properties,id'],
            'parking_type'   => ['nullable', 'string', 'max:100'],
            'parking_number' => ['sometimes', 'string', 'max:100'],
        ]);

        $spot->update($data);
        ActivityLog::record('parking_spot', $spot->id, 'updated', 'تم تعديل الموقف — ' . $spot->parking_number);

        return response()->json([
            'message' => 'تم تعديل الموقف بنجاح',
            'data' => $spot->fresh()->load(['association', 'property']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $spot = ParkingSpot::findOrFail($id);
        $spot->delete();
        ActivityLog::record('parking_spot', $id, 'deleted', 'تم حذف الموقف');

        return response()->json(['message' => 'تم حذف الموقف بنجاح']);
    }
}
