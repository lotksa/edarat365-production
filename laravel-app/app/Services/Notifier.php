<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Central notification dispatcher.
 *
 * Every domain controller emits a single, well-known event-key
 * (e.g. `invoice.created`, `maintenance.status_changed`) via
 * `Notifier::dispatch(...)`. This service handles all the plumbing:
 *
 *   1. Look up the notification settings (admin + owner toggles per event).
 *   2. Decide who should receive the notification (admin role users,
 *      a single owner, or a single user).
 *   3. Insert one row in `notifications` per recipient. Each row holds
 *      bilingual title/body, a deep-link, level (info/success/warning/danger)
 *      and JSON payload with extra context.
 *
 * The service is intentionally defensive: any failure (missing settings,
 * unmatched event-key, DB error) is logged and swallowed so the originating
 * domain action (create invoice, etc.) is never aborted because a downstream
 * notification couldn't be sent.
 *
 * Adding a new event:
 *   1. Pick a stable key like `<module>.<verb>` (snake_case).
 *   2. Add a row to `EVENT_CATALOG` below with title/body templates,
 *      audience, level, module and the default-on flag.
 *   3. Call `Notifier::dispatch('module.verb', ['subject' => $model, ...])`
 *      from the controller.
 *   4. Optionally: expose a toggle for it in the notification settings UI
 *      (the catalog already drives that page).
 */
class Notifier
{
    /**
     * Canonical catalog of every event the platform can emit.
     *
     * Each entry:
     *   - module       Module key (matches sidebar grouping)
     *   - audience     'admin' | 'owner' | both via array
     *   - level        info | success | warning | danger
     *   - title_ar/en  Title template (supports {placeholders} from `data`)
     *   - body_ar/en   Body template
     *   - default      Default enabled state if the user hasn't configured yet
     */
    public const EVENT_CATALOG = [
        // ── Invoices ─────────────────────────────────────────────────────
        'invoice.created' => [
            'module' => 'invoices', 'audience' => ['admin', 'owner'], 'level' => 'info', 'default' => true,
            'title_ar' => 'فاتورة جديدة', 'title_en' => 'New invoice',
            'body_ar'  => 'تم إنشاء فاتورة جديدة رقم {number} بقيمة {amount} ر.س.',
            'body_en'  => 'New invoice {number} has been created for {amount} SAR.',
        ],
        'invoice.paid' => [
            'module' => 'invoices', 'audience' => ['admin', 'owner'], 'level' => 'success', 'default' => true,
            'title_ar' => 'فاتورة مدفوعة', 'title_en' => 'Invoice paid',
            'body_ar'  => 'تم تسديد الفاتورة رقم {number} بقيمة {amount} ر.س.',
            'body_en'  => 'Invoice {number} has been paid ({amount} SAR).',
        ],
        'invoice.cancelled' => [
            'module' => 'invoices', 'audience' => ['admin', 'owner'], 'level' => 'warning', 'default' => true,
            'title_ar' => 'إلغاء فاتورة', 'title_en' => 'Invoice cancelled',
            'body_ar'  => 'تم إلغاء الفاتورة رقم {number}.',
            'body_en'  => 'Invoice {number} has been cancelled.',
        ],
        'invoice.overdue' => [
            'module' => 'invoices', 'audience' => ['admin', 'owner'], 'level' => 'danger', 'default' => true,
            'title_ar' => 'فاتورة متأخرة', 'title_en' => 'Overdue invoice',
            'body_ar'  => 'الفاتورة رقم {number} متأخرة عن موعد الاستحقاق ({due_date}).',
            'body_en'  => 'Invoice {number} is overdue (due {due_date}).',
        ],
        'invoice.due_soon' => [
            'module' => 'invoices', 'audience' => ['owner'], 'level' => 'warning', 'default' => true,
            'title_ar' => 'تذكير: فاتورة قاربت على الاستحقاق', 'title_en' => 'Invoice due soon',
            'body_ar'  => 'الفاتورة رقم {number} تستحق في {due_date}.',
            'body_en'  => 'Invoice {number} is due on {due_date}.',
        ],

        // ── Vouchers ─────────────────────────────────────────────────────
        'voucher.created' => [
            'module' => 'vouchers', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'سند جديد', 'title_en' => 'New voucher',
            'body_ar'  => 'تم إنشاء {type} رقم {number} بقيمة {amount} ر.س.',
            'body_en'  => 'New {type} {number} created for {amount} SAR.',
        ],

        // ── Maintenance ──────────────────────────────────────────────────
        'maintenance.created' => [
            'module' => 'maintenance', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'طلب صيانة جديد', 'title_en' => 'New maintenance request',
            'body_ar'  => '{title} — الأولوية: {priority}',
            'body_en'  => '{title} — priority: {priority}',
        ],
        'maintenance.status_changed' => [
            'module' => 'maintenance', 'audience' => ['admin', 'owner'], 'level' => 'info', 'default' => true,
            'title_ar' => 'تحديث حالة طلب الصيانة', 'title_en' => 'Maintenance status updated',
            'body_ar'  => 'طلب الصيانة #{id} أصبح {status}.',
            'body_en'  => 'Maintenance request #{id} is now {status}.',
        ],
        'maintenance.completed' => [
            'module' => 'maintenance', 'audience' => ['admin', 'owner'], 'level' => 'success', 'default' => true,
            'title_ar' => 'اكتمل طلب صيانة', 'title_en' => 'Maintenance completed',
            'body_ar'  => 'تم الانتهاء من طلب الصيانة: {title}.',
            'body_en'  => 'Maintenance request "{title}" has been completed.',
        ],

        // ── Meetings ─────────────────────────────────────────────────────
        'meeting.scheduled' => [
            'module' => 'meetings', 'audience' => ['admin', 'owner'], 'level' => 'info', 'default' => true,
            'title_ar' => 'اجتماع جديد', 'title_en' => 'New meeting',
            'body_ar'  => 'تمت جدولة اجتماع "{title}" بتاريخ {scheduled_at}.',
            'body_en'  => 'Meeting "{title}" scheduled for {scheduled_at}.',
        ],
        'meeting.reminder' => [
            'module' => 'meetings', 'audience' => ['admin', 'owner'], 'level' => 'warning', 'default' => true,
            'title_ar' => 'تذكير باجتماع قادم', 'title_en' => 'Upcoming meeting reminder',
            'body_ar'  => 'تذكير: لديك اجتماع "{title}" في {scheduled_at}.',
            'body_en'  => 'Reminder: meeting "{title}" at {scheduled_at}.',
        ],
        'meeting.cancelled' => [
            'module' => 'meetings', 'audience' => ['admin', 'owner'], 'level' => 'warning', 'default' => true,
            'title_ar' => 'إلغاء اجتماع', 'title_en' => 'Meeting cancelled',
            'body_ar'  => 'تم إلغاء الاجتماع "{title}".',
            'body_en'  => 'Meeting "{title}" has been cancelled.',
        ],
        'meeting.minutes_published' => [
            'module' => 'meetings', 'audience' => ['admin', 'owner'], 'level' => 'info', 'default' => true,
            'title_ar' => 'محضر اجتماع جديد', 'title_en' => 'Meeting minutes published',
            'body_ar'  => 'تم نشر محضر اجتماع "{title}".',
            'body_en'  => 'Minutes for "{title}" are now available.',
        ],

        // ── Votes ────────────────────────────────────────────────────────
        'vote.created' => [
            'module' => 'votes', 'audience' => ['admin', 'owner'], 'level' => 'info', 'default' => true,
            'title_ar' => 'تصويت جديد', 'title_en' => 'New vote',
            'body_ar'  => 'تم إنشاء تصويت جديد: {title}.',
            'body_en'  => 'A new vote has been created: {title}.',
        ],
        'vote.phase_changed' => [
            'module' => 'votes', 'audience' => ['admin', 'owner'], 'level' => 'info', 'default' => true,
            'title_ar' => 'تحديث مرحلة التصويت', 'title_en' => 'Vote phase updated',
            'body_ar'  => 'انتقل التصويت "{title}" إلى المرحلة {phase}.',
            'body_en'  => 'Vote "{title}" moved to phase {phase}.',
        ],
        'vote.closed' => [
            'module' => 'votes', 'audience' => ['admin', 'owner'], 'level' => 'success', 'default' => true,
            'title_ar' => 'انتهاء التصويت', 'title_en' => 'Vote closed',
            'body_ar'  => 'تم إغلاق التصويت "{title}".',
            'body_en'  => 'Vote "{title}" has been closed.',
        ],

        // ── Legal Cases ──────────────────────────────────────────────────
        'legal_case.created' => [
            'module' => 'legal_cases', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'قضية قانونية جديدة', 'title_en' => 'New legal case',
            'body_ar'  => 'تم فتح قضية جديدة رقم {number}: {title}.',
            'body_en'  => 'New legal case {number}: {title}.',
        ],
        'legal_case.status_changed' => [
            'module' => 'legal_cases', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'تحديث حالة القضية', 'title_en' => 'Legal case status updated',
            'body_ar'  => 'القضية رقم {number} أصبحت {status}.',
            'body_en'  => 'Case {number} is now {status}.',
        ],
        'legal_case.hearing_reminder' => [
            'module' => 'legal_cases', 'audience' => ['admin'], 'level' => 'warning', 'default' => true,
            'title_ar' => 'تذكير بجلسة قضية', 'title_en' => 'Court hearing reminder',
            'body_ar'  => 'جلسة القضية {number} بتاريخ {hearing_date}.',
            'body_en'  => 'Hearing for case {number} on {hearing_date}.',
        ],
        'legal_case.update_added' => [
            'module' => 'legal_cases', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'تحديث جديد على قضية', 'title_en' => 'Legal case update',
            'body_ar'  => 'تم إضافة تحديث جديد على القضية {number}.',
            'body_en'  => 'A new update has been added to case {number}.',
        ],

        // ── Approvals ────────────────────────────────────────────────────
        'approval.created' => [
            'module' => 'approvals', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'طلب موافقة جديد', 'title_en' => 'New approval request',
            'body_ar'  => 'تم إنشاء طلب موافقة جديد: {title}.',
            'body_en'  => 'A new approval request was created: {title}.',
        ],
        'approval.approved' => [
            'module' => 'approvals', 'audience' => ['admin'], 'level' => 'success', 'default' => true,
            'title_ar' => 'تمت الموافقة', 'title_en' => 'Approval granted',
            'body_ar'  => 'تمت الموافقة على الطلب: {title}.',
            'body_en'  => 'Request "{title}" has been approved.',
        ],
        'approval.rejected' => [
            'module' => 'approvals', 'audience' => ['admin'], 'level' => 'danger', 'default' => true,
            'title_ar' => 'رفض طلب موافقة', 'title_en' => 'Approval rejected',
            'body_ar'  => 'تم رفض الطلب: {title}.',
            'body_en'  => 'Request "{title}" has been rejected.',
        ],

        // ── Contracts ────────────────────────────────────────────────────
        'contract.created' => [
            'module' => 'contracts', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'عقد جديد', 'title_en' => 'New contract',
            'body_ar'  => 'تم إنشاء عقد جديد رقم {number}.',
            'body_en'  => 'A new contract {number} has been created.',
        ],
        'contract.expiring_soon' => [
            'module' => 'contracts', 'audience' => ['admin', 'owner'], 'level' => 'warning', 'default' => true,
            'title_ar' => 'عقد قارب على الانتهاء', 'title_en' => 'Contract expiring soon',
            'body_ar'  => 'العقد رقم {number} ينتهي في {end_date}.',
            'body_en'  => 'Contract {number} expires on {end_date}.',
        ],
        'contract.expired' => [
            'module' => 'contracts', 'audience' => ['admin', 'owner'], 'level' => 'danger', 'default' => true,
            'title_ar' => 'انتهاء عقد', 'title_en' => 'Contract expired',
            'body_ar'  => 'العقد رقم {number} منتهي.',
            'body_en'  => 'Contract {number} has expired.',
        ],

        // ── Bookings ─────────────────────────────────────────────────────
        'booking.created' => [
            'module' => 'bookings', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'حجز مرفق جديد', 'title_en' => 'New facility booking',
            'body_ar'  => 'تم إنشاء حجز جديد لمرفق #{facility_id}.',
            'body_en'  => 'A new booking for facility #{facility_id} has been created.',
        ],
        'booking.cancelled' => [
            'module' => 'bookings', 'audience' => ['admin', 'owner'], 'level' => 'warning', 'default' => true,
            'title_ar' => 'إلغاء حجز', 'title_en' => 'Booking cancelled',
            'body_ar'  => 'تم إلغاء الحجز #{id}.',
            'body_en'  => 'Booking #{id} has been cancelled.',
        ],

        // ── Owners / Properties / Units (admin-only operational events) ──
        'owner.created' => [
            'module' => 'owners', 'audience' => ['admin'], 'level' => 'info', 'default' => false,
            'title_ar' => 'مالك جديد', 'title_en' => 'New owner',
            'body_ar'  => 'تم إضافة المالك {name}.',
            'body_en'  => 'Owner {name} has been added.',
        ],
        'property.created' => [
            'module' => 'properties', 'audience' => ['admin'], 'level' => 'info', 'default' => false,
            'title_ar' => 'عقار جديد', 'title_en' => 'New property',
            'body_ar'  => 'تم إضافة العقار {name}.',
            'body_en'  => 'Property "{name}" has been added.',
        ],
        'unit.created' => [
            'module' => 'units', 'audience' => ['admin'], 'level' => 'info', 'default' => false,
            'title_ar' => 'وحدة جديدة', 'title_en' => 'New unit',
            'body_ar'  => 'تمت إضافة الوحدة {number}.',
            'body_en'  => 'Unit {number} has been added.',
        ],

        // ── Users ────────────────────────────────────────────────────────
        'user.created' => [
            'module' => 'users', 'audience' => ['admin'], 'level' => 'info', 'default' => true,
            'title_ar' => 'مستخدم جديد', 'title_en' => 'New user',
            'body_ar'  => 'تم إنشاء حساب للمستخدم {name}.',
            'body_en'  => 'A new user account was created: {name}.',
        ],
        'user.deactivated' => [
            'module' => 'users', 'audience' => ['admin'], 'level' => 'warning', 'default' => true,
            'title_ar' => 'إيقاف مستخدم', 'title_en' => 'User deactivated',
            'body_ar'  => 'تم إيقاف الحساب: {name}.',
            'body_en'  => 'User account "{name}" has been deactivated.',
        ],

        // ── System ───────────────────────────────────────────────────────
        'system.announcement' => [
            'module' => 'system', 'audience' => ['admin', 'owner'], 'level' => 'info', 'default' => true,
            'title_ar' => 'إعلان من النظام', 'title_en' => 'System announcement',
            'body_ar'  => '{message}',
            'body_en'  => '{message}',
        ],
        'system.security_alert' => [
            'module' => 'system', 'audience' => ['admin'], 'level' => 'danger', 'default' => true,
            'title_ar' => 'تنبيه أمني', 'title_en' => 'Security alert',
            'body_ar'  => '{message}',
            'body_en'  => '{message}',
        ],
    ];

    /**
     * Dispatch a notification.
     *
     * @param  string  $eventKey   Catalog key, e.g. `invoice.created`.
     * @param  array   $options    {
     *     @type array       data    Token map for {placeholders} + persisted JSON.
     *     @type ?Model      subject Optional model — auto-fills subject_type/id.
     *     @type ?string     link    Deep link (frontend route).
     *     @type ?int        owner_id Force-target a single owner.
     *     @type ?int        user_id Force-target a single user.
     *     @type ?array      audience Override default audience list.
     * }
     */
    public static function dispatch(string $eventKey, array $options = []): void
    {
        try {
            $event = self::EVENT_CATALOG[$eventKey] ?? null;
            if (!$event) {
                Log::warning("Notifier: unknown event-key {$eventKey}");
                return;
            }

            $settings = Setting::getByKey('notifications', []);
            $audience = $options['audience'] ?? (array) ($event['audience'] ?? ['admin']);

            $data    = (array) ($options['data'] ?? []);
            $subject = $options['subject'] ?? null;
            $link    = $options['link'] ?? self::resolveLink($eventKey, $subject, $data);

            $titleAr = self::interpolate($event['title_ar'] ?? $eventKey, $data);
            $titleEn = self::interpolate($event['title_en'] ?? $eventKey, $data);
            $bodyAr  = self::interpolate($event['body_ar'] ?? '', $data);
            $bodyEn  = self::interpolate($event['body_en'] ?? '', $data);

            $base = [
                'event_key' => $eventKey,
                'module'    => $event['module'] ?? 'system',
                'title_ar'  => $titleAr,
                'title_en'  => $titleEn,
                'body_ar'   => $bodyAr,
                'body_en'   => $bodyEn,
                'level'     => $event['level'] ?? 'info',
                'data'      => $data,
                'link'      => $link,
            ];

            if ($subject) {
                $base['subject_type'] = method_exists($subject, 'getMorphClass')
                    ? $subject->getMorphClass()
                    : strtolower(class_basename($subject));
                $base['subject_id'] = $subject->getKey();
            }

            // Admin audience → fan-out to every user whose role can view this module.
            if (in_array('admin', $audience, true) && self::isAudienceEnabled('admin', $eventKey, $settings, $event['default'] ?? true)) {
                $recipients = self::resolveAdminRecipients($event['module'] ?? 'system');
                foreach ($recipients as $userId) {
                    Notification::create(array_merge($base, [
                        'audience' => 'admin',
                        'user_id'  => $userId,
                    ]));
                }
            }

            // Owner audience → single owner if owner_id passed.
            $ownerId = $options['owner_id'] ?? null;
            if ($ownerId && in_array('owner', $audience, true) && self::isAudienceEnabled('owner', $eventKey, $settings, $event['default'] ?? true)) {
                Notification::create(array_merge($base, [
                    'audience' => 'owner',
                    'owner_id' => (int) $ownerId,
                ]));
            }

            // Direct user target (skips role gates — used for things like
            // approval-assignee notifications).
            $directUser = $options['user_id'] ?? null;
            if ($directUser) {
                Notification::create(array_merge($base, [
                    'audience' => 'user',
                    'user_id'  => (int) $directUser,
                ]));
            }
        } catch (\Throwable $e) {
            Log::warning("Notifier dispatch failed for {$eventKey}: " . $e->getMessage());
        }
    }

    /**
     * Convenience: dispatch only when something actually changed (used by
     * status-change hooks to avoid duplicate rows when controllers call
     * `update()` with the same status).
     */
    public static function dispatchIfChanged(string $eventKey, $oldValue, $newValue, array $options = []): void
    {
        if ($oldValue === $newValue) {
            return;
        }
        self::dispatch($eventKey, $options);
    }

    private static function interpolate(string $template, array $data): string
    {
        if ($template === '' || !$data) {
            return $template;
        }
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($data) {
            $key = $m[1];
            $value = $data[$key] ?? '';
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_array($value) || is_object($value)) {
                return '';
            }
            return (string) $value;
        }, $template);
    }

    /**
     * Default deep link based on event-key + subject. Each catalog entry
     * can override via `options['link']`.
     */
    private static function resolveLink(string $eventKey, $subject, array $data): ?string
    {
        if (!$subject || !method_exists($subject, 'getKey')) {
            return null;
        }
        $id = $subject->getKey();
        $module = strtok($eventKey, '.');
        return match ($module) {
            'invoice'      => "/invoices/{$id}",
            'voucher'      => isset($data['type']) && $data['type'] === 'payment'
                                ? "/vouchers/payments/{$id}"
                                : "/vouchers/receipts/{$id}",
            'maintenance'  => "/maintenance/{$id}",
            'meeting'      => "/meetings/{$id}",
            'vote'         => "/votes/{$id}",
            'legal_case'   => "/legal-cases/{$id}",
            'approval'     => "/approval-workflows/{$id}",
            'contract'     => "/contracts/{$id}",
            'booking'      => "/facilities",
            'owner'        => "/owners/{$id}",
            'property'     => "/properties/{$id}",
            'unit'         => "/units/{$id}",
            'user'         => "/users",
            default        => null,
        };
    }

    /**
     * Has the admin (or owner) toggled this event on in `Settings → Notifications`?
     */
    private static function isAudienceEnabled(string $audience, string $eventKey, array $settings, bool $default): bool
    {
        // Settings store the legacy `admin.new_invoice` style keys as well as
        // the new event-key style. Try the event key first, fall back to the
        // legacy key, finally fall back to the catalog default.
        $bucket = $settings[$audience] ?? null;
        if (!is_array($bucket)) {
            return $default;
        }
        if (array_key_exists($eventKey, $bucket)) {
            return (bool) $bucket[$eventKey];
        }
        // Legacy mapping (kept for backwards compatibility with existing rows).
        $legacy = match ($eventKey) {
            'invoice.created'             => 'new_invoice',
            'invoice.due_soon'            => 'invoice_reminder',
            'maintenance.created'         => 'new_maintenance',
            'maintenance.status_changed'  => 'maintenance_update',
            'meeting.scheduled'           => $audience === 'owner' ? 'meeting_notification' : 'new_meeting',
            'legal_case.created'          => 'new_legal_case',
            'approval.created'            => 'new_approval',
            'system.announcement'         => $audience === 'owner' ? 'system_announcements' : 'system_alerts',
            'system.security_alert'       => 'system_alerts',
            default                       => null,
        };
        if ($legacy && array_key_exists($legacy, $bucket)) {
            return (bool) $bucket[$legacy];
        }
        return $default;
    }

    /**
     * Returns user-ids that should receive an admin notification for the
     * given module. Strategy:
     *   - Super admins always receive everything.
     *   - Users whose role permission set contains `{module}.view` (or any
     *     `{module}.*` permission) receive the notification too.
     *
     * This way a finance team only sees invoice/voucher notifications, a
     * legal team only sees case notifications, etc., without having to
     * configure anything per user.
     */
    private static function resolveAdminRecipients(string $module): array
    {
        $allActive = User::query()->where('is_active', true)->with('userRole.permissions:id,key')->get();
        $ids = [];
        foreach ($allActive as $u) {
            $role = $u->userRole;
            if (!$role) {
                continue;
            }
            if ($role->key === 'super_admin') {
                $ids[] = $u->id;
                continue;
            }
            $keys = $role->permissions->pluck('key')->all();
            foreach ($keys as $key) {
                if (str_starts_with($key, $module . '.')) {
                    $ids[] = $u->id;
                    break;
                }
            }
        }
        return array_values(array_unique($ids));
    }
}
