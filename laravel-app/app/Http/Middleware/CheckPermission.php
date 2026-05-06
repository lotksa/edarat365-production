<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $userPermissions = $user->permissionKeys();
        foreach ($permissions as $perm) {
            if (in_array($perm, $userPermissions, true)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'ليس لديك الصلاحية للقيام بهذا الإجراء',
            'required_permissions' => $permissions,
        ], 403);
    }
}
