<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Role::query()->withCount('users')->with('permissions:id,key');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('key', 'like', "%{$search}%")
                  ->orWhere('name_ar', 'like', "%{$search}%")
                  ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        $records = $query->orderBy('id')->get();

        return response()->json([
            'data' => $records->map(fn ($role) => [
                'id'              => $role->id,
                'key'             => $role->key,
                'name_ar'         => $role->name_ar,
                'name_en'         => $role->name_en,
                'description_ar'  => $role->description_ar,
                'description_en'  => $role->description_en,
                'color'           => $role->color,
                'is_system'       => $role->is_system,
                'is_active'       => $role->is_active,
                'users_count'     => $role->users_count,
                'permissions_count' => $role->permissions->count(),
                'permission_keys' => $role->permissions->pluck('key'),
                'created_at'      => $role->created_at,
                'updated_at'      => $role->updated_at,
            ]),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $role = Role::with('permissions')->withCount('users')->findOrFail($id);

        return response()->json([
            'data' => [
                'id'              => $role->id,
                'key'             => $role->key,
                'name_ar'         => $role->name_ar,
                'name_en'         => $role->name_en,
                'description_ar'  => $role->description_ar,
                'description_en'  => $role->description_en,
                'color'           => $role->color,
                'is_system'       => $role->is_system,
                'is_active'       => $role->is_active,
                'users_count'     => $role->users_count,
                'permissions'     => $role->permissions,
                'permission_keys' => $role->permissions->pluck('key'),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $total = Role::count();
        $active = Role::where('is_active', true)->count();
        $custom = Role::where('is_system', false)->count();

        return response()->json([
            'total'  => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'system' => $total - $custom,
            'custom' => $custom,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'             => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'unique:roles,key'],
            'name_ar'         => ['required', 'string', 'max:255'],
            'name_en'         => ['required', 'string', 'max:255'],
            'description_ar'  => ['nullable', 'string', 'max:500'],
            'description_en'  => ['nullable', 'string', 'max:500'],
            'color'           => ['nullable', 'string', 'max:32'],
            'is_active'       => ['nullable', 'boolean'],
            'permissions'     => ['nullable', 'array'],
            'permissions.*'   => ['string', 'exists:permissions,key'],
        ], [
            'key.regex' => 'مفتاح الدور يجب أن يحتوي على أحرف صغيرة وأرقام وشرطة سفلية فقط',
            'key.unique'=> 'مفتاح الدور مستخدم مسبقاً',
        ]);

        $role = Role::create([
            'key'             => $data['key'],
            'name_ar'         => $data['name_ar'],
            'name_en'         => $data['name_en'],
            'description_ar'  => $data['description_ar'] ?? null,
            'description_en'  => $data['description_en'] ?? null,
            'color'           => $data['color'] ?? '#021B4A',
            'is_active'       => $data['is_active'] ?? true,
            'is_system'       => false,
        ]);

        if (!empty($data['permissions'])) {
            $role->syncPermissionKeys($data['permissions']);
        }

        ActivityLog::record('role', $role->id, 'created', 'تم إنشاء دور جديد', null, $role->only(['key','name_ar','name_en']));

        return response()->json([
            'message' => 'تم إنشاء الدور بنجاح',
            'data'    => $role->load('permissions'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $oldValues = $role->only(['name_ar','name_en','description_ar','description_en','is_active']);

        $data = $request->validate([
            'name_ar'         => ['sometimes', 'string', 'max:255'],
            'name_en'         => ['sometimes', 'string', 'max:255'],
            'description_ar'  => ['nullable', 'string', 'max:500'],
            'description_en'  => ['nullable', 'string', 'max:500'],
            'color'           => ['nullable', 'string', 'max:32'],
            'is_active'       => ['nullable', 'boolean'],
        ]);

        $role->update($data);

        ActivityLog::record('role', $role->id, 'updated', 'تم تحديث الدور', $oldValues, $role->fresh()->only(['name_ar','name_en','description_ar','description_en','is_active']));

        return response()->json([
            'message' => 'تم تحديث الدور بنجاح',
            'data'    => $role->fresh()->load('permissions'),
        ]);
    }

    public function syncPermissions(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($role->key === 'super_admin') {
            return response()->json([
                'message' => 'دور مدير النظام يحصل على جميع الصلاحيات تلقائياً ولا يمكن تعديل صلاحياته',
            ], 422);
        }

        $data = $request->validate([
            'permissions'   => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,key'],
        ]);

        $role->syncPermissionKeys($data['permissions']);

        ActivityLog::record('role', $role->id, 'permissions_synced',
            'تم تحديث صلاحيات الدور',
            null,
            ['permissions' => $data['permissions']]
        );

        return response()->json([
            'message' => 'تم حفظ الصلاحيات بنجاح',
            'data'    => $role->load('permissions'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::withCount('users')->findOrFail($id);

        if ($role->is_system) {
            return response()->json(['message' => 'لا يمكن حذف الأدوار الافتراضية للنظام'], 422);
        }

        if ($role->users_count > 0) {
            return response()->json([
                'message' => "لا يمكن حذف الدور لارتباطه بـ {$role->users_count} مستخدم. يرجى نقل المستخدمين إلى دور آخر أولاً.",
            ], 422);
        }

        ActivityLog::record('role', $role->id, 'deleted', 'تم حذف الدور', $role->only(['key','name_ar','name_en']));
        $role->delete();

        return response()->json(['message' => 'تم حذف الدور بنجاح']);
    }
}
