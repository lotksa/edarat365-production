<?php

namespace App\Http\Middleware;

use App\Models\Association;
use App\Services\AssociationContext;
use App\Services\WorkspaceWrites;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UseAssociationWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(AssociationContext::class);
        $context->id = null;
        $path = preg_replace('#^api/v1/?#', '', $request->path());
        $root = explode('/', $path)[0];

        // These are deliberately system-wide. Permission middleware still runs.
        $global = in_array($root, ['auth', 'account', 'settings', 'cities', 'users', 'roles', 'permissions', 'workspace'], true)
            || $path === 'reports/users'
            || (in_array($root, ['notifications', 'search'], true) && ! $request->header('X-Association-Id'));
        $gateway = ($root === 'associations' && ! preg_match('#^associations/\d+#', $path))
            || preg_match('#^(export|import)/associations(?:/template)?$#', $path);
        $gatewayPresidentLookup = $root === 'association-managers' && ! $request->header('X-Association-Id')
            && $request->isMethod('GET') && $request->query('all') === 'true'
            && ($request->user()->hasPermission('associations.create') || $request->user()->hasPermission('associations.update'));

        if ($global || $gatewayPresidentLookup || ($gateway && ! $request->header('X-Association-Id'))) {
            return $next($request);
        }

        $id = $request->header('X-Association-Id');
        if (! $id && preg_match('#^associations/(\d+)(?:/|$)#', $path, $matches)) {
            $id = $matches[1]; // Existing association bookmarks have an unambiguous context.
        }
        if (! is_scalar($id) || ! preg_match('/^[1-9]\d*$/', (string) $id)) {
            return response()->json(['message' => 'اختر الجمعية أولاً', 'code' => 'association_required'], 409);
        }
        $association = Association::findOrFail((int) $id);
        // Existing RBAC is authoritative; a header selects a workspace and never
        // grants new permissions. Non-staff owner accounts may enter only a
        // workspace linked to their profile's actual units/properties.
        if (! $request->user()->isSuperAdmin() && ! $request->user()->hasPermission('associations.view')) {
            $owner = $request->user()->ownerProfile;
            $owns = $owner && ($owner->properties()->where('association_id', $association->id)->exists()
                || $owner->units()->whereHas('property', fn ($q) => $q->where('association_id', $association->id))->exists());
            abort_unless($owns, 403, 'ليس لديك صلاحية الدخول إلى هذه الجمعية');
        }

        $context->id = (int) $association->id;
        try {
            if (preg_match('#^associations/(\d+)(?:/|$)#', $path, $matches)) {
                abort_unless((int) $matches[1] === $context->id, 404);
            }
            app(WorkspaceWrites::class)->validateRequest($request, $root);
            if (! $request->isMethod('GET') && ! $request->isMethod('HEAD') && in_array($root, [
                'properties', 'contracts', 'invoices', 'meetings', 'votes', 'facilities',
                'maintenance', 'maintenance-requests', 'legal-cases', 'vehicles', 'parking-spots', 'transactions',
            ], true)) {
                $request->merge(['association_id' => $context->id]);
            }
            $response = $next($request);
            if ($response instanceof StreamedResponse) {
                $callback = $response->getCallback();
                $selected = $context->id;
                $response->setCallback(function () use ($callback, $context, $selected) {
                    $context->id = $selected;
                    try {
                        $callback();
                    } finally {
                        $context->id = null;
                    }
                });
            }

            return $response;
        } finally {
            $context->id = null; // Required for long-lived workers and test clients.
        }
    }
}
