<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalRepresentative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalRepresentativeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LegalRepresentative::with('user');

        if ($s = $request->query('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('firm_name', 'like', "%{$s}%")
                  ->orWhere('license_number', 'like', "%{$s}%");
            });
        }
        if ($v = $request->query('status')) $query->where('status', $v);
        if ($v = $request->query('specialty')) $query->where('specialty', $v);

        $items = $query->latest()->paginate($request->query('per_page', 15));

        return response()->json($items);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total'    => LegalRepresentative::count(),
            'active'   => LegalRepresentative::where('status', 'active')->count(),
            'inactive' => LegalRepresentative::where('status', 'inactive')->count(),
        ]);
    }

    public function show($id): JsonResponse
    {
        $rep = LegalRepresentative::with('user', 'casePermissions.legalCase')->findOrFail($id);
        return response()->json($rep);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:30',
            'specialty'      => 'nullable|string|max:100',
            'license_number' => 'nullable|string|max:100',
            'firm_name'      => 'nullable|string|max:255',
            'user_id'        => 'nullable|exists:users,id',
            'status'         => 'nullable|in:active,inactive',
            'notes'          => 'nullable|string',
        ]);

        $data['created_by'] = auth()->id();
        $rep = LegalRepresentative::create($data);

        return response()->json($rep, 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $rep = LegalRepresentative::findOrFail($id);

        $data = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:30',
            'specialty'      => 'nullable|string|max:100',
            'license_number' => 'nullable|string|max:100',
            'firm_name'      => 'nullable|string|max:255',
            'user_id'        => 'nullable|exists:users,id',
            'status'         => 'nullable|in:active,inactive',
            'notes'          => 'nullable|string',
        ]);

        $rep->update($data);
        return response()->json($rep);
    }

    public function destroy($id): JsonResponse
    {
        LegalRepresentative::findOrFail($id)->delete();
        return response()->json(['message' => 'deleted']);
    }
}
