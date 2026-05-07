<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssociationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssociationManagerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AssociationManager::query();

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
        $total = AssociationManager::count();
        $active = AssociationManager::where('status', 'active')->count();
        return response()->json([
            'total' => $total, 'active' => $active, 'inactive' => $total - $active,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name'   => ['required', 'string', 'max:255'],
            'national_id' => ['required', 'string', 'size:10', 'regex:/^\d{10}$/', new \App\Rules\UniqueEncrypted('association_managers', 'national_id_hash')],
            'phone'       => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/'],
            'email'       => ['nullable', 'email', 'max:255'],
        ], [
            'national_id.size'  => 'رقم الهوية يجب أن يكون 10 أرقام',
            'national_id.regex' => 'رقم الهوية يجب أن يكون 10 أرقام فقط',
            'phone.size'        => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex'       => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
        ]);

        $data['status'] = 'active';
        $manager = AssociationManager::create($data);

        return response()->json(['message' => 'President created', 'data' => $manager], 201);
    }

    public function show(int $id): JsonResponse
    {
        $mgr = AssociationManager::with('associations')->findOrFail($id);
        return response()->json(['data' => $mgr]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $mgr = AssociationManager::findOrFail($id);
        $data = $request->validate([
            'full_name'   => ['sometimes', 'string', 'max:255'],
            'national_id' => ['sometimes', 'string', 'size:10', 'regex:/^\d{10}$/', new \App\Rules\UniqueEncrypted('association_managers', 'national_id_hash', ignoreId: (int) $id)],
            'phone'       => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/'],
            'email'       => ['nullable', 'email', 'max:255'],
        ], [
            'national_id.size'  => 'رقم الهوية يجب أن يكون 10 أرقام',
            'national_id.regex' => 'رقم الهوية يجب أن يكون 10 أرقام فقط',
            'phone.size'        => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex'       => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
        ]);
        $mgr->update($data);
        return response()->json(['message' => 'Updated', 'data' => $mgr->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        AssociationManager::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $mgr = AssociationManager::findOrFail($id);
        $mgr->status = $mgr->status === 'active' ? 'inactive' : 'active';
        $mgr->save();
        return response()->json(['message' => 'Status updated', 'data' => $mgr]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:association_managers,id'],
        ]);
        $count = AssociationManager::whereIn('id', $data['ids'])->delete();
        return response()->json(['message' => "Deleted {$count}", 'count' => $count]);
    }
}
