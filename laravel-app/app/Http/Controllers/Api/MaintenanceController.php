<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceRequest;
use Illuminate\Http\{Request, JsonResponse};

class MaintenanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = MaintenanceRequest::with(['association', 'property', 'unit', 'owner']);

        if ($request->filled('association_id')) {
            $q->where('association_id', $request->association_id);
        }
        if ($request->filled('property_id')) {
            $q->where('property_id', $request->property_id);
        }
        if ($request->filled('owner_id')) {
            $q->where('owner_id', $request->owner_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $q->where('priority', $request->priority);
        }
        if ($request->filled('type')) {
            $q->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($q2) use ($s) {
                $q2->where('title', 'LIKE', "%{$s}%")
                   ->orWhere('description', 'LIKE', "%{$s}%")
                   ->orWhere('assigned_to', 'LIKE', "%{$s}%")
                   ->orWhereHas('owner', fn($o) => $o->where('full_name', 'LIKE', "%{$s}%"));
            });
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
        $item = MaintenanceRequest::with(['association', 'property', 'unit', 'owner'])->findOrFail($id);
        return response()->json(['data' => $item]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'association_id' => 'nullable|exists:associations,id',
            'property_id' => 'nullable|exists:properties,id',
            'owner_id' => 'nullable|exists:owners,id',
            'unit_id' => 'nullable|exists:units,id',
            'title' => 'required|string|max:255',
            'type' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'priority' => 'required|string|in:low,medium,high,urgent',
            'status' => 'nullable|string|in:open,in_progress,on_hold,completed,closed,cancelled',
            'assigned_to' => 'nullable|string|max:255',
            'assigned_phone' => 'nullable|string|max:50',
            'estimated_cost' => 'nullable|numeric|min:0',
            'actual_cost' => 'nullable|numeric|min:0',
            'scheduled_date' => 'nullable|date',
            'images' => 'nullable|array',
        ]);

        $payload = $request->all();
        if (!isset($payload['status'])) $payload['status'] = 'open';

        $item = MaintenanceRequest::create($payload);
        $item->load(['association', 'property', 'unit', 'owner']);

        return response()->json(['data' => $item, 'message' => 'created'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = MaintenanceRequest::findOrFail($id);

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'type' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'priority' => 'sometimes|string|in:low,medium,high,urgent',
            'status' => 'sometimes|string|in:open,in_progress,on_hold,completed,closed,cancelled',
            'assigned_to' => 'nullable|string|max:255',
            'assigned_phone' => 'nullable|string|max:50',
            'estimated_cost' => 'nullable|numeric|min:0',
            'actual_cost' => 'nullable|numeric|min:0',
            'scheduled_date' => 'nullable|date',
            'completed_date' => 'nullable|date',
            'resolution_notes' => 'nullable|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'images' => 'nullable|array',
        ]);

        $oldStatus = $item->status;
        $item->update($request->all());

        if ($oldStatus !== 'completed' && $request->status === 'completed' && !$item->completed_date) {
            $item->update(['completed_date' => now()->toDateString()]);
        }

        $item->load(['association', 'property', 'unit', 'owner']);
        return response()->json(['data' => $item, 'message' => 'updated']);
    }

    public function destroy(int $id): JsonResponse
    {
        MaintenanceRequest::findOrFail($id)->delete();
        return response()->json(['message' => 'deleted']);
    }

    public function stats(Request $request): JsonResponse
    {
        $q = MaintenanceRequest::query();
        if ($request->filled('association_id')) $q->where('association_id', $request->association_id);
        if ($request->filled('property_id')) $q->where('property_id', $request->property_id);
        if ($request->filled('owner_id')) $q->where('owner_id', $request->owner_id);

        $total = $q->count();
        $open = (clone $q)->where('status', 'open')->count();
        $inProgress = (clone $q)->where('status', 'in_progress')->count();
        $onHold = (clone $q)->where('status', 'on_hold')->count();
        $completed = (clone $q)->where('status', 'completed')->count();
        $closed = (clone $q)->where('status', 'closed')->count();
        $cancelled = (clone $q)->where('status', 'cancelled')->count();
        $urgent = (clone $q)->where('priority', 'urgent')->where('status', '!=', 'completed')->where('status', '!=', 'closed')->count();

        return response()->json(compact('total', 'open', 'inProgress', 'onHold', 'completed', 'closed', 'cancelled', 'urgent'));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $item = MaintenanceRequest::findOrFail($id);

        $request->validate([
            'status' => 'required|string|in:open,in_progress,on_hold,completed,closed,cancelled',
        ]);

        $item->update(['status' => $request->status]);

        if ($request->status === 'completed' && !$item->completed_date) {
            $item->update(['completed_date' => now()->toDateString()]);
        }

        return response()->json(['data' => $item, 'message' => 'status_updated']);
    }
}
