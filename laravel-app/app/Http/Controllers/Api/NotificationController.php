<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\Notifier;
use Illuminate\Http\{Request, JsonResponse};

/**
 * REST endpoints for the in-app notification bell and the dedicated
 * Notifications page.
 *
 * All endpoints scope to the current authenticated user. Owners (which are
 * not yet a User row) are supported via the `owner_id` column — when an
 * authenticated user has an associated owner record, they also receive any
 * notifications routed to that owner.
 */
class NotificationController extends Controller
{
    /**
     * Paginated list of notifications for the current viewer.
     *
     * Query params:
     *   - filter:    all | unread | read   (default: all)
     *   - module:    invoices, meetings, …  (optional)
     *   - level:     info | success | warning | danger
     *   - q:         text search on title/body
     *   - per_page:  default 20, max 100
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['data' => [], 'total' => 0, 'unread' => 0]);
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $filter  = $request->input('filter', 'all');
        $module  = $request->input('module');
        $level   = $request->input('level');
        $q       = trim((string) $request->input('q', ''));

        $query = Notification::query()->where(function ($w) use ($user) {
            $w->where('user_id', $user->id);
            // Owners are matched both by user_id (admin/system users) and by
            // owner_id when the current user is linked to an owner record.
            $ownerId = $user->ownerProfile?->id ?? null;
            if ($ownerId) {
                $w->orWhere('owner_id', $ownerId);
            }
        });

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }
        if ($module) {
            $query->where('module', $module);
        }
        if ($level) {
            $query->where('level', $level);
        }
        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($w) use ($like) {
                $w->where('title_ar', 'LIKE', $like)
                  ->orWhere('title_en', 'LIKE', $like)
                  ->orWhere('body_ar',  'LIKE', $like)
                  ->orWhere('body_en',  'LIKE', $like);
            });
        }

        $total = (clone $query)->count();
        $unread = (clone $query)->whereNull('read_at')->count();

        $items = $query->orderByDesc('created_at')
            ->limit($perPage)
            ->offset(($request->input('page', 1) - 1) * $perPage)
            ->get();

        return response()->json([
            'data'   => $items,
            'total'  => $total,
            'unread' => $unread,
            'per_page' => $perPage,
            'page'   => (int) $request->input('page', 1),
        ]);
    }

    /** Cheap polling endpoint for the topbar badge. */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['count' => 0]);
        }
        $ownerId = $user->ownerProfile?->id ?? null;
        $count = Notification::query()
            ->where(function ($w) use ($user, $ownerId) {
                $w->where('user_id', $user->id);
                if ($ownerId) {
                    $w->orWhere('owner_id', $ownerId);
                }
            })
            ->whereNull('read_at')
            ->count();
        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $notif = Notification::findOrFail($id);
        if (!$this->canAccess($notif, $user)) {
            return response()->json(['message' => 'forbidden'], 403);
        }
        if (!$notif->read_at) {
            $notif->read_at = now();
            $notif->save();
        }
        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['updated' => 0]);
        $ownerId = $user->ownerProfile?->id ?? null;
        $updated = Notification::query()
            ->where(function ($w) use ($user, $ownerId) {
                $w->where('user_id', $user->id);
                if ($ownerId) $w->orWhere('owner_id', $ownerId);
            })
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return response()->json(['updated' => $updated]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $notif = Notification::findOrFail($id);
        if (!$this->canAccess($notif, $user)) {
            return response()->json(['message' => 'forbidden'], 403);
        }
        $notif->delete();
        return response()->json(['ok' => true]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['deleted' => 0]);
        $ownerId = $user->ownerProfile?->id ?? null;
        $deleted = Notification::query()
            ->where(function ($w) use ($user, $ownerId) {
                $w->where('user_id', $user->id);
                if ($ownerId) $w->orWhere('owner_id', $ownerId);
            })
            ->delete();
        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Returns the full event catalog so the settings page can render every
     * available toggle without duplicating the list in the frontend.
     */
    public function catalog(): JsonResponse
    {
        $catalog = [];
        foreach (Notifier::EVENT_CATALOG as $key => $meta) {
            $catalog[] = [
                'event_key' => $key,
                'module'    => $meta['module'] ?? 'system',
                'audience'  => (array) ($meta['audience'] ?? ['admin']),
                'level'     => $meta['level'] ?? 'info',
                'default'   => (bool) ($meta['default'] ?? true),
                'title_ar'  => $meta['title_ar'] ?? $key,
                'title_en'  => $meta['title_en'] ?? $key,
                'body_ar'   => $meta['body_ar'] ?? '',
                'body_en'   => $meta['body_en'] ?? '',
            ];
        }
        return response()->json(['catalog' => $catalog]);
    }

    /**
     * Test endpoint to fire a sample announcement for the current user —
     * lets admins verify the bell + page wiring end-to-end without having
     * to actually create an invoice.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'unauthenticated'], 401);
        }
        Notifier::dispatch('system.announcement', [
            'audience' => ['admin'],
            'user_id'  => $user->id,
            'data'     => [
                'message' => $request->input('message', 'إشعار تجريبي من نظام الإشعارات'),
            ],
        ]);
        return response()->json(['ok' => true]);
    }

    private function canAccess(Notification $n, $user): bool
    {
        if (!$user) return false;
        if ($n->user_id && $n->user_id === $user->id) return true;
        $ownerId = $user->ownerProfile?->id ?? null;
        if ($ownerId && $n->owner_id === $ownerId) return true;
        return false;
    }
}
