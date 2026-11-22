<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Owner;
use App\Models\Role;
use App\Models\User;
use App\Scopes\AssociationScope;
use App\Services\AssociationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class OwnerPortalAccessController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $user = Owner::findOrFail($id)->user;
        $portal = app(AssociationContext::class)->isEndowments() ? 'endowment' : 'owner';

        return response()->json(['data' => $user ? $user->only('id', 'name', 'email', 'is_active') + ['is_owner' => $user->portal() === $portal] : null]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['create', 'link'])],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^05\d{8}$/', 'unique:users,phone'],
            'password' => ['required_if:mode,create', 'nullable', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->hasPermission($data['mode'] === 'create' ? 'users.create' : 'users.update'), 403);

        return DB::transaction(function () use ($id, $data) {
            $owner = Owner::lockForUpdate()->findOrFail($id);
            $endowments = app(AssociationContext::class)->isEndowments();
            $roleKey = $endowments ? 'endowment_holder' : 'owner';
            $portal = $endowments ? 'endowment' : 'owner';
            if ($owner->user_id) {
                throw ValidationException::withMessages(['email' => 'هذا المالك مرتبط بحساب بالفعل.']);
            }
            $email = mb_strtolower(trim($data['email']));
            $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            if ($data['mode'] === 'create') {
                if ($user) {
                    throw ValidationException::withMessages(['email' => 'البريد مسجل بالفعل. استخدم ربط حساب مالك موجود أو بريدًا آخر.']);
                }
                $role = Role::where('key', $roleKey)->firstOrFail();
                $user = User::create([
                    'name' => $owner->full_name, 'email' => $email, 'phone' => $data['phone'] ?? null,
                    'password' => $data['password'], 'password_changed_at' => now(), 'role' => $roleKey, 'role_id' => $role->id, 'is_active' => true,
                ]);
            } elseif (! $user || $user->portal() !== $portal || ! $user->is_active) {
                throw ValidationException::withMessages(['email' => $endowments
                    ? 'اختر بريد حساب نشط لصاحب وقف.' : 'اختر بريد حساب مالك نشط؛ لا يمكن ربط حساب إداري.']);
            }
            if (Owner::withoutGlobalScope(AssociationScope::class)->withTrashed()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'الحساب مرتبط بسجل مالك آخر.']);
            }
            $owner->user_id = $user->id;
            $owner->save();
            $message = $endowments ? 'تم ربط حساب بوابة أصحاب الوقف' : 'تم ربط حساب بوابة المالك';
            ActivityLog::record('owner', $owner->id, 'portal_linked', $message, null, ['user_id' => $user->id]);

            return response()->json(['message' => $message.'.', 'data' => $user->only('id', 'name', 'email', 'is_active') + ['is_owner' => true]], 201);
        });
    }
}
