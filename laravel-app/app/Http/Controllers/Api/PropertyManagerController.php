<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyManagerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PropertyManager::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('national_id', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->query('all') === 'true') {
            return response()->json(['data' => $query->orderBy('full_name')->get()]);
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
        $total = PropertyManager::count();
        $active = PropertyManager::where('status', 'active')->count();

        return response()->json([
            'total'    => $total,
            'active'   => $active,
            'inactive' => $total - $active,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name'   => ['required', 'string', 'max:255'],
            'national_id' => ['required', 'string', 'size:10', 'regex:/^\d{10}$/', 'unique:property_managers,national_id'],
            'phone'       => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/'],
            'email'       => ['nullable', 'email', 'max:255'],
        ], [
            'national_id.size'  => 'رقم الهوية يجب أن يكون 10 أرقام',
            'national_id.regex' => 'رقم الهوية يجب أن يكون 10 أرقام فقط',
            'phone.size'        => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex'       => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
        ]);

        $data['status'] = 'active';
        $manager = PropertyManager::create($data);

        return response()->json(['message' => 'Property manager created', 'data' => $manager], 201);
    }

    public function show(int $id): JsonResponse
    {
        $manager = PropertyManager::with('properties')->findOrFail($id);

        return response()->json(['data' => $manager]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $manager = PropertyManager::findOrFail($id);

        $data = $request->validate([
            'full_name'   => ['sometimes', 'string', 'max:255'],
            'national_id' => ['sometimes', 'string', 'size:10', 'regex:/^\d{10}$/', 'unique:property_managers,national_id,' . $id],
            'phone'       => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/'],
            'email'       => ['nullable', 'email', 'max:255'],
        ], [
            'national_id.size'  => 'رقم الهوية يجب أن يكون 10 أرقام',
            'national_id.regex' => 'رقم الهوية يجب أن يكون 10 أرقام فقط',
            'phone.size'        => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex'       => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
        ]);

        $manager->update($data);

        return response()->json(['message' => 'Updated', 'data' => $manager->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        PropertyManager::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $manager = PropertyManager::findOrFail($id);
        $manager->status = $manager->status === 'active' ? 'inactive' : 'active';
        $manager->save();

        return response()->json(['message' => 'Status updated', 'data' => $manager]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:property_managers,id'],
        ]);

        $count = PropertyManager::whereIn('id', $data['ids'])->delete();

        return response()->json(['message' => "Deleted {$count}", 'count' => $count]);
    }
}
