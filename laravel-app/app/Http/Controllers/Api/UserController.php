<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        // Recent timeline (capped) — full paginated history is exposed via
        // the dedicated /users/{id}/activity-log endpoint.
        $logs = ActivityLog::query()
            ->where(function ($q) use ($id) {
                $q->where('performer_id', $id)
                  ->orWhere(function ($qq) use ($id) {
                      $qq->where('subject_type', 'user')->where('subject_id', $id);
                  });
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $data = $user->toArray();
        $data['activity_logs'] = $logs;

        return response()->json(['data' => $data]);
    }

    /**
     * Paginated activity log for a single user. Combines:
     *   - actions performed BY this user (performer_id = $id), and
     *   - actions whose subject IS this user (login/logout, password
     *     changes, status toggles, role updates).
     *
     * Query params: page, per_page (default 25, max 100), action (filter).
     */
    public function activityLog(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $perPage = min((int) $request->query('per_page', 25), 100);
        $action = $request->query('action');

        $query = ActivityLog::query()
            ->where(function ($q) use ($id) {
                $q->where('performer_id', $id)
                  ->orWhere(function ($qq) use ($id) {
                      $qq->where('subject_type', 'user')->where('subject_id', $id);
                  });
            });

        if ($action) {
            $query->where('action', $action);
        }

        $records = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
            'user' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /**
     * Upload (or replace) the user's profile picture. Stored on the
     * public disk under users/avatars/. Old file is removed on replace.
     */
    public function uploadAvatar(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
        ], [
            'avatar.required' => 'يرجى اختيار صورة',
            'avatar.image'    => 'الملف يجب أن يكون صورة',
            'avatar.mimes'    => 'صيغ الصور المسموحة: jpg, jpeg, png, webp, gif',
            'avatar.max'      => 'حجم الصورة الأقصى 4 ميجابايت',
        ]);

        if (!empty($user->avatar_path) && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('users/avatars', 'public');
        $user->avatar_path = $path;
        $user->save();

        ActivityLog::record('user', $user->id, 'avatar_updated', 'تم تحديث الصورة الشخصية');

        return response()->json([
            'message' => 'تم تحديث الصورة الشخصية',
            'data'    => $user->fresh()->load('userRole'),
        ]);
    }

    /**
     * Remove the user's uploaded profile picture (does not touch the
     * legacy `avatar_url` column).
     */
    public function removeAvatar(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if (!empty($user->avatar_path) && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->avatar_path = null;
        $user->save();

        ActivityLog::record('user', $user->id, 'avatar_removed', 'تم إزالة الصورة الشخصية');

        return response()->json([
            'message' => 'تم إزالة الصورة الشخصية',
            'data'    => $user->fresh()->load('userRole'),
        ]);
    }

    /**
     * Reset (set) a user's password from the User Detail modal. Enforces
     * the same strong-password policy used in store/update; revokes all
     * outstanding tokens so the prior session cannot continue.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'password' => ['required', \Illuminate\Validation\Rules\Password::min(12)
                ->letters()->mixedCase()->numbers()->symbols()],
        ], [
            'password.required' => 'كلمة المرور مطلوبة',
        ]);

        $user->password = $data['password']; // hashed cast handles hashing
        $user->password_changed_at = now();
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();

        // Force re-login on every device so the old token cannot continue.
        $user->tokens()->delete();

        ActivityLog::record('user', $user->id, 'password_reset', 'تم إعادة تعيين كلمة المرور');
        SecurityAuditLog::record('auth.password.reset_by_admin', 'success', [],
            auth()->user(), null, ['type' => 'user', 'id' => $user->id], $request);

        return response()->json([
            'message' => 'تم إعادة تعيين كلمة المرور',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'    => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/', 'unique:users,phone'],
            'role_id'  => ['required', 'integer', 'exists:roles,id'],
            'password' => ['nullable', \Illuminate\Validation\Rules\Password::min(12)
                ->letters()->mixedCase()->numbers()->symbols()],
            'is_active'=> ['nullable', 'boolean'],
        ], [
            'phone.size'  => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex' => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'email.unique'=> 'البريد الإلكتروني مستخدم مسبقاً',
            'phone.unique'=> 'رقم الجوال مستخدم مسبقاً',
        ]);

        $role = Role::findOrFail($data['role_id']);

        $user = User::create([
            'name'                 => $data['name'],
            'email'                => $data['email'],
            'phone'                => $data['phone'] ?? null,
            'role_id'              => $role->id,
            'role'                 => $role->key,
            'is_active'            => $data['is_active'] ?? true,
            // The User model's `hashed` cast hashes the password automatically.
            'password'             => $data['password'] ?? \Illuminate\Support\Str::random(20),
            'password_changed_at'  => now(),
        ]);

        ActivityLog::record('user', $user->id, 'created', 'تم إنشاء مستخدم جديد', null, $user->only(['name','email','phone','role']));

        SecurityAuditLog::record('rbac.user.created', 'success', [
            'role_id' => $role->id,
            'role'    => $role->key,
        ], auth()->user(), null, ['type' => 'user', 'id' => $user->id]);

        return response()->json([
            'message' => 'تم إنشاء المستخدم بنجاح',
            'data'    => $user->load('userRole'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $oldValues = $user->only(['name','email','phone','role','role_id','is_active']);
        $oldRoleId = $user->role_id;

        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'email', 'max:255', Rule::unique('users','email')->ignore($id)],
            'phone'    => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/', Rule::unique('users','phone')->ignore($id)],
            'role_id'  => ['sometimes', 'integer', 'exists:roles,id'],
            'password' => ['nullable', \Illuminate\Validation\Rules\Password::min(12)
                ->letters()->mixedCase()->numbers()->symbols()],
            'is_active'=> ['nullable', 'boolean'],
        ]);

        if (isset($data['role_id'])) {
            $role = Role::findOrFail($data['role_id']);
            $data['role'] = $role->key;
        }

        $passwordWasSet = !empty($data['password']);
        if ($passwordWasSet) {
            // Rely on the User model's `hashed` cast — never double-hash.
            $data['password_changed_at'] = now();
        } else {
            unset($data['password']);
        }

        $user->update($data);

        ActivityLog::record('user', $user->id, 'updated', 'تم تحديث بيانات المستخدم', $oldValues, $user->fresh()->only(['name','email','phone','role','role_id','is_active']));

        if (isset($data['role_id']) && $data['role_id'] !== $oldRoleId) {
            SecurityAuditLog::record('rbac.role.changed', 'success', [
                'old_role_id' => $oldRoleId,
                'new_role_id' => $data['role_id'],
            ], auth()->user(), null, ['type' => 'user', 'id' => $user->id]);
            // SECURITY: revoke active sessions so the new role takes effect immediately
            // and any stolen pre-change token cannot retain elevated privileges.
            $user->tokens()->delete();
        }
        if ($passwordWasSet) {
            SecurityAuditLog::record('auth.password.changed_by_admin', 'success', [], auth()->user(), null, ['type' => 'user', 'id' => $user->id]);
            $user->tokens()->delete();
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] !== ($oldValues['is_active'] ?? null)) {
            SecurityAuditLog::record('rbac.user.' . ($data['is_active'] ? 'activated' : 'deactivated'), 'success', [], auth()->user(), null, ['type' => 'user', 'id' => $user->id]);
            if (!$data['is_active']) $user->tokens()->delete();
        }

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

        // Disallow disabling the last super admin (lock-out protection).
        if ($user->isSuperAdmin() && $user->is_active) {
            $activeSuperAdmins = User::query()
                ->where('is_active', true)
                ->whereHas('userRole', fn ($q) => $q->where('key', 'super_admin'))
                ->count();
            if ($activeSuperAdmins <= 1) {
                return response()->json(['message' => 'لا يمكن تعطيل آخر مدير نظام نشط'], 422);
            }
        }

        $user->is_active = !$user->is_active;
        $user->save();

        ActivityLog::record('user', $user->id, 'status_changed',
            $user->is_active ? 'تم تفعيل المستخدم' : 'تم تعطيل المستخدم'
        );

        SecurityAuditLog::record(
            'rbac.user.' . ($user->is_active ? 'activated' : 'deactivated'),
            'success', [], auth()->user(), null,
            ['type' => 'user', 'id' => $user->id]
        );

        // SECURITY: revoke active sessions when an account is disabled so a
        // stolen token cannot keep operating after the admin disabled the user.
        if (!$user->is_active) {
            $user->tokens()->delete();
        }

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
