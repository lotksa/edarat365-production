<?php

namespace App\Http\Middleware;

use App\Services\AssociationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OwnerPortalBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if(! $user || $user->is_active === false, 403, 'الحساب غير مفعل. تواصل مع الإدارة.');
        $portal = $request->route('customer_portal');
        $accountPortal = $user->portal();
        abort_if($portal && $portal !== $accountPortal, 403, 'الحساب غير مرتبط بهذه البوابة.');
        // Even an accidentally granted staff permission cannot open administrative
        // APIs, exports or print routes from an owner account.
        abort_if($accountPortal && ! $portal && ! $request->is('api/v1/auth/me', 'api/v1/auth/logout'),
            403, 'هذا الحساب مخصص لبوابة العميل.');

        if (! $portal) {
            return $next($request);
        }

        $context = app(AssociationContext::class);
        $context->section = $portal === 'endowment' ? AssociationContext::ENDOWMENTS : AssociationContext::OWNERS;
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } finally {
            $context->section = null;
            $context->id = null;
        }
    }
}
