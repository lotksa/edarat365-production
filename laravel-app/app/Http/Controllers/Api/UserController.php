<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('userRole');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($roleId = $request->query('role_id')) {
            $query->where('role_id', $roleId);
        }

        if ($status = $request->query('status')) {
            $query->where('is_active', $status === 'active');
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
        $total = User::count();
        $active = User::where('is_active', true)->count();
        $byRole = User::query()
            ->selectRaw('role_id, COUNT(*) as total')
            ->groupBy('role_id')
            ->with('userRole:id,key,name_ar,name_en,color')
            ->get();

        return response()->json([
            'total'    => $total,
            'active'   => $active,
            'inactive' => $total - $active,
            'by_role'  => $byRole,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::with(['userRole.permissions'])->findOrFail($id);
        return response()->json(['data' => $user]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'    => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/', 'unique:users,phone'],
            'role_id'  => ['required', 'integer', 'exists:roles,id'],
            'password' => ['nullable', 'string', 'min:6'],
            'is_active'=> ['nullable', 'boolean'],
        ], [
            'phone.size'  => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex' => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'email.unique'=> 'البريد الإلكتروني مستخدم مسبقاً',
            'phone.unique'=> 'رقم الجوال مستخدم مسبقاً',
        ]);

        $role = Role::findOrFail($data['role_id']);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'role_id'   => $role->id,
            'role'      => $role->key,
            'is_active' => $data['is_active'] ?? true,
            'password'  => Hash::make($data['password'] ?? str()->random(12)),
        ]);

        ActivityLog::record('user', $user->id, 'created', 'تم إنشاء مستخدم جديد', null, $user->only(['name','email','phone','role']));

        return response()->json([
            'message' => 'تم إنشاء المستخدم بنجاح',
            'data'    => $user->load('userRole'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $oldValues = $user->only(['name','email','phone','role','role_id','is_active']);

        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'email', 'max:255', Rule::unique('users','email')->ignore($id)],
            'phone'    => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/', Rule::unique('users','phone')->ignore($id)],
            'role_id'  => ['sometimes', 'integer', 'exists:roles,id'],
            'password' => ['nullable', 'string', 'min:6'],
            'is_active'=> ['nullable', 'boolean'],
        ]);

        if (isset($data['role_id'])) {
            $role = Role::findOrFail($data['role_id']);
            $data['role'] = $role->key;
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        ActivityLog::record('user', $user->id, 'updated', 'تم تحديث بيانات المستخدم', $oldValues, $user->fresh()->only(['name','email','phone','role','role_id','is_active']));

        return response()->json([
            'message' => 'تم تحديث المستخدم بنجاح',
            'data'    => $user->load('userRole')->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if (auth()->id() === $user->id) {
            return response()->json(['message' => 'لا يمكن حذف حسابك الحالي'], 422);
        }

        if ($user->isSuperAdmin()) {
            $superAdminCount = User::whereHas('userRole', fn ($q) => $q->where('key', 'super_admin'))->count();
            if ($superAdminCount <= 1) {
                return response()->json(['message' => 'لا يمكن حذف آخر مدير نظام'], 422);
            }
        }

        ActivityLog::record('user', $user->id, 'deleted', 'تم حذف المستخدم', $user->only(['name','email','phone']));
        $user->delete();

        return response()->json(['message' => 'تم حذف المستخدم بنجاح']);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if (auth()->id() === $user->id) {
            return response()->json(['message' => 'لا يمكن تعديل حالة حسابك الحالي'], 422);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        ActivityLog::record('user', $user->id, 'status_changed',
            $user->is_active ? 'تم تفعيل المستخدم' : 'تم تعطيل المستخدم'
        );

        return response()->json([
            'message' => $user->is_active ? 'تم تفعيل المستخدم' : 'تم تعطيل المستخدم',
            'data'    => $user,
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
        ]);

        $deleted = 0;
        $blocked = [];
        foreach (User::with('userRole')->whereIn('id', $data['ids'])->get() as $user) {
            if (auth()->id() === $user->id) {
                $blocked[] = $user->name . ' (حسابك الحالي)';
                continue;
            }
            if ($user->isSuperAdmin()) {
                $superAdminCount = User::whereHas('userRole', fn ($q) => $q->where('key', 'super_admin'))->count();
                if ($superAdminCount <= 1) {
                    $blocked[] = $user->name . ' (آخر مدير نظام)';
                    continue;
                }
            }
            $user->delete();
            $deleted++;
        }

        $msg = "تم حذف {$deleted} مستخدم";
        if (!empty($blocked)) {
            $msg .= '. لم يتم حذف: ' . implode('، ', $blocked);
        }

        return response()->json(['message' => $msg, 'count' => $deleted, 'blocked' => $blocked]);
    }
}
