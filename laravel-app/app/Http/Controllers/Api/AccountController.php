<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->enrichUser($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        // SECURITY: Privilege fields (role, role_id, is_active, locked_until,
        // password_changed_at) are intentionally excluded — only an admin via
        // the protected /users endpoint may change them.
        $payload = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone'      => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/', Rule::unique('users', 'phone')->ignore($user?->id)],
            // Legacy avatar_url is kept for backward compatibility but the
            // canonical avatar is now an uploaded file stored at avatar_path
            // (managed via POST/DELETE /account/avatar). When the URL is
            // provided we still accept it; relative storage URLs that came
            // back from a prior upload are also accepted (so resubmitting the
            // form doesn't fail validation on what we ourselves emitted).
            'avatar_url' => ['nullable', 'string', 'max:1024'],
        ], [
            'phone.size'  => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex' => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
        ]);

        $user?->update($payload);

        // CRITICAL: Return the FULLY-ENRICHED user (permissions, role_info,
        // is_super_admin). The previous implementation returned a bare
        // ->fresh() user, which the frontend store wrote over the
        // authenticated user — wiping permissions[] and collapsing the
        // sidebar after every profile save.
        return response()->json([
            'message' => 'تم تحديث الملف الشخصي',
            'data'    => $this->enrichUser($user?->fresh()),
        ]);
    }

    /**
     * Upload (or replace) the authenticated user's avatar. Self-service:
     * no users.update permission required — the caller is editing their
     * own account. Old file is removed before the new one is saved.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

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
            'data'    => $this->enrichUser($user->fresh()),
        ]);
    }

    /**
     * Remove the authenticated user's avatar — falls back to initials.
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!empty($user->avatar_path) && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->avatar_path = null;
        $user->save();

        ActivityLog::record('user', $user->id, 'avatar_removed', 'تم إزالة الصورة الشخصية');

        return response()->json([
            'message' => 'تم إزالة الصورة الشخصية',
            'data'    => $this->enrichUser($user->fresh()),
        ]);
    }

    /**
     * Wrap a user model with the auxiliary fields the SPA expects on the
     * authenticated user: full permission key list, super-admin flag, and
     * the role object (role_info). Shared by every endpoint that returns
     * "the current user" so partial responses never wipe these fields
     * from the frontend store.
     */
    private function enrichUser(?User $user): ?array
    {
        if (!$user) return null;
        $user->load('userRole.permissions');
        $data = $user->toArray();
        $data['permissions']     = $user->permissionKeys();
        $data['is_super_admin']  = $user->isSuperAdmin();
        $data['role_info']       = $user->userRole;
        return $data;
    }
}
