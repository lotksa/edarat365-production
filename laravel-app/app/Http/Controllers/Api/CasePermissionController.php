<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CasePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasePermissionController extends Controller
{
    public function index($caseId): JsonResponse
    {
        $perms = CasePermission::where('legal_case_id', $caseId)
            ->with('user', 'representative', 'granter')
            ->get();

        return response()->json($perms);
    }

    public function store(Request $request, $caseId): JsonResponse
    {
        $data = $request->validate([
            'user_id'           => 'nullable|exists:users,id',
            'representative_id' => 'nullable|exists:legal_representatives,id',
            'role'              => 'required|in:lawyer,property_manager,association_head,owner,viewer',
            'permissions'       => 'nullable|array',
            'permissions.can_write'            => 'nullable|boolean',
            'permissions.can_reply'            => 'nullable|boolean',
            'permissions.can_view_updates'     => 'nullable|boolean',
            'permissions.can_view_attachments' => 'nullable|boolean',
            'permissions.can_view_messages'    => 'nullable|boolean',
        ]);

        $data['legal_case_id'] = $caseId;
        $data['granted_by'] = auth()->id();

        $data['permissions'] = array_merge([
            'can_write' => false,
            'can_reply' => true,
            'can_view_updates' => true,
            'can_view_attachments' => true,
            'can_view_messages' => true,
        ], $data['permissions'] ?? []);

        $perm = CasePermission::create($data);
        $perm->load('user', 'representative');

        return response()->json($perm, 201);
    }

    public function update(Request $request, $caseId, $permId): JsonResponse
    {
        $perm = CasePermission::where('legal_case_id', $caseId)->findOrFail($permId);

        $data = $request->validate([
            'role'        => 'nullable|in:lawyer,property_manager,association_head,owner,viewer',
            'permissions' => 'nullable|array',
        ]);

        if (isset($data['permissions'])) {
            $data['permissions'] = array_merge($perm->permissions ?? [], $data['permissions']);
        }

        $perm->update($data);
        $perm->load('user', 'representative');

        return response()->json($perm);
    }

    public function destroy($caseId, $permId): JsonResponse
    {
        CasePermission::where('legal_case_id', $caseId)->findOrFail($permId)->delete();
        return response()->json(['message' => 'deleted']);
    }
}
