<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::query()->orderBy('group_key')->orderBy('id')->get();

        $grouped = [];
        foreach ($permissions as $perm) {
            $g = $perm->group_key;
            if (!isset($grouped[$g])) {
                $grouped[$g] = [
                    'group_key'     => $g,
                    'group_name_ar' => $perm->group_name_ar,
                    'group_name_en' => $perm->group_name_en,
                    'permissions'   => [],
                ];
            }
            $grouped[$g]['permissions'][] = [
                'id'      => $perm->id,
                'key'     => $perm->key,
                'name_ar' => $perm->name_ar,
                'name_en' => $perm->name_en,
            ];
        }

        return response()->json([
            'data' => array_values($grouped),
            'flat' => $permissions->map(fn ($p) => [
                'id' => $p->id, 'key' => $p->key,
                'group_key' => $p->group_key,
                'name_ar' => $p->name_ar, 'name_en' => $p->name_en,
            ]),
        ]);
    }
}
