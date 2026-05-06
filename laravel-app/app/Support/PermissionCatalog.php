<?php

namespace App\Support;

class PermissionCatalog
{
    /**
     * All permissions grouped by module.
     * Key: permission key
     * Value: [group_key, group_name_ar, group_name_en, name_ar, name_en]
     */
    public static function all(): array
    {
        return array_merge(
            self::group('dashboard', 'لوحة المعلومات', 'Dashboard', [
                'dashboard.view' => ['عرض لوحة المعلومات', 'View dashboard'],
            ]),
            self::group('users', 'إدارة المستخدمين', 'Users Management', [
                'users.view' => ['عرض المستخدمين', 'View users'],
                'users.create' => ['إنشاء مستخدم', 'Create user'],
                'users.update' => ['تعديل مستخدم', 'Update user'],
                'users.delete' => ['حذف مستخدم', 'Delete user'],
                'users.toggle_status' => ['تفعيل/تعطيل مستخدم', 'Toggle user status'],
            ]),
            self::group('roles', 'الأدوار والصلاحيات', 'Roles & Permissions', [
                'roles.view' => ['عرض الأدوار', 'View roles'],
                'roles.create' => ['إنشاء دور', 'Create role'],
                'roles.update' => ['تعديل دور', 'Update role'],
                'roles.delete' => ['حذف دور', 'Delete role'],
                'roles.assign_permissions' => ['تعيين الصلاحيات', 'Assign permissions'],
            ]),
            self::group('owners', 'الملاك', 'Owners', [
                'owners.view' => ['عرض الملاك', 'View owners'],
                'owners.create' => ['إنشاء مالك', 'Create owner'],
                'owners.update' => ['تعديل مالك', 'Update owner'],
                'owners.delete' => ['حذف مالك', 'Delete owner'],
                'owners.export' => ['تصدير الملاك', 'Export owners'],
            ]),
            self::group('associations', 'الجمعيات', 'Associations', [
                'associations.view' => ['عرض الجمعيات', 'View associations'],
                'associations.create' => ['إنشاء جمعية', 'Create association'],
                'associations.update' => ['تعديل جمعية', 'Update association'],
                'associations.delete' => ['حذف جمعية', 'Delete association'],
            ]),
            self::group('properties', 'العقارات', 'Properties', [
                'properties.view' => ['عرض العقارات', 'View properties'],
                'properties.create' => ['إنشاء عقار', 'Create property'],
                'properties.update' => ['تعديل عقار', 'Update property'],
                'properties.delete' => ['حذف عقار', 'Delete property'],
            ]),
            self::group('units', 'الوحدات', 'Units', [
                'units.view' => ['عرض الوحدات', 'View units'],
                'units.create' => ['إنشاء وحدة', 'Create unit'],
                'units.update' => ['تعديل وحدة', 'Update unit'],
                'units.delete' => ['حذف وحدة', 'Delete unit'],
            ]),
            self::group('contracts', 'العقود', 'Contracts', [
                'contracts.view' => ['عرض العقود', 'View contracts'],
                'contracts.create' => ['إنشاء عقد', 'Create contract'],
                'contracts.update' => ['تعديل عقد', 'Update contract'],
                'contracts.delete' => ['حذف عقد', 'Delete contract'],
                'contracts.print' => ['طباعة العقود', 'Print contracts'],
            ]),
            self::group('meetings', 'الاجتماعات والقرارات', 'Meetings & Resolutions', [
                'meetings.view' => ['عرض الاجتماعات', 'View meetings'],
                'meetings.create' => ['إنشاء اجتماع', 'Create meeting'],
                'meetings.update' => ['تعديل اجتماع', 'Update meeting'],
                'meetings.delete' => ['حذف اجتماع', 'Delete meeting'],
                'meetings.attendance' => ['تحضير الحضور', 'Manage attendance'],
            ]),
            self::group('votes', 'التصويتات', 'Votes', [
                'votes.view' => ['عرض التصويتات', 'View votes'],
                'votes.create' => ['إنشاء تصويت', 'Create vote'],
                'votes.update' => ['تعديل تصويت', 'Update vote'],
                'votes.delete' => ['حذف تصويت', 'Delete vote'],
            ]),
            self::group('invoices', 'الفواتير', 'Invoices', [
                'invoices.view' => ['عرض الفواتير', 'View invoices'],
                'invoices.create' => ['إنشاء فاتورة', 'Create invoice'],
                'invoices.update' => ['تعديل فاتورة', 'Update invoice'],
                'invoices.delete' => ['حذف فاتورة', 'Delete invoice'],
                'invoices.print' => ['طباعة فواتير', 'Print invoices'],
            ]),
            self::group('vouchers', 'سندات القبض/الصرف', 'Vouchers', [
                'vouchers.view' => ['عرض السندات', 'View vouchers'],
                'vouchers.create' => ['إنشاء سند', 'Create voucher'],
                'vouchers.update' => ['تعديل سند', 'Update voucher'],
                'vouchers.delete' => ['حذف سند', 'Delete voucher'],
            ]),
            self::group('facilities', 'المرافق', 'Facilities', [
                'facilities.view' => ['عرض المرافق', 'View facilities'],
                'facilities.create' => ['إنشاء مرفق', 'Create facility'],
                'facilities.update' => ['تعديل مرفق', 'Update facility'],
                'facilities.delete' => ['حذف مرفق', 'Delete facility'],
                'facilities.book' => ['حجز مرفق', 'Book facility'],
                'facilities.cancel_booking' => ['إلغاء حجز', 'Cancel booking'],
            ]),
            self::group('maintenance', 'الصيانة', 'Maintenance', [
                'maintenance.view' => ['عرض طلبات الصيانة', 'View maintenance'],
                'maintenance.create' => ['إنشاء طلب صيانة', 'Create maintenance'],
                'maintenance.update' => ['تعديل طلب صيانة', 'Update maintenance'],
                'maintenance.delete' => ['حذف طلب صيانة', 'Delete maintenance'],
                'maintenance.update_status' => ['تحديث الحالة', 'Update status'],
            ]),
            self::group('vehicles', 'إدارة الحركة', 'Vehicles & Parking', [
                'vehicles.view' => ['عرض المركبات والمواقف', 'View vehicles & parking'],
                'vehicles.create' => ['إضافة مركبة/موقف', 'Add vehicle/parking'],
                'vehicles.update' => ['تعديل', 'Update'],
                'vehicles.delete' => ['حذف', 'Delete'],
            ]),
            self::group('legal_cases', 'القضايا القانونية', 'Legal Cases', [
                'legal_cases.view' => ['عرض القضايا', 'View cases'],
                'legal_cases.create' => ['إنشاء قضية', 'Create case'],
                'legal_cases.update' => ['تعديل قضية', 'Update case'],
                'legal_cases.delete' => ['حذف قضية', 'Delete case'],
            ]),
            self::group('approvals', 'الموافقات', 'Approvals', [
                'approvals.view' => ['عرض الموافقات', 'View approvals'],
                'approvals.approve' => ['الموافقة', 'Approve'],
                'approvals.reject' => ['الرفض', 'Reject'],
            ]),
            self::group('reports', 'التقارير', 'Reports', [
                'reports.view' => ['عرض التقارير', 'View reports'],
                'reports.export' => ['تصدير التقارير', 'Export reports'],
            ]),
            self::group('settings', 'الإعدادات', 'Settings', [
                'settings.view' => ['عرض الإعدادات', 'View settings'],
                'settings.update' => ['تعديل الإعدادات', 'Update settings'],
                'settings.integrations' => ['ربط الأنظمة', 'Manage integrations'],
                'settings.mail' => ['إعدادات البريد', 'Mail settings'],
                'settings.sms' => ['إعدادات الرسائل', 'SMS settings'],
            ]),
            self::group('activity_log', 'السجل التاريخي', 'Activity Log', [
                'activity_log.view' => ['عرض السجل', 'View activity log'],
            ]),
        );
    }

    private static function group(string $key, string $ar, string $en, array $perms): array
    {
        $out = [];
        foreach ($perms as $permKey => $names) {
            $out[$permKey] = [
                'group_key' => $key,
                'group_name_ar' => $ar,
                'group_name_en' => $en,
                'name_ar' => $names[0],
                'name_en' => $names[1],
            ];
        }
        return $out;
    }

    public static function defaultRoles(): array
    {
        $all = array_keys(self::all());

        return [
            [
                'key' => 'super_admin',
                'name_ar' => 'مدير النظام',
                'name_en' => 'Super Admin',
                'description_ar' => 'صلاحية كاملة على جميع وحدات المنصة بما فيها إدارة المستخدمين والأدوار',
                'description_en' => 'Full access to every platform module including users and roles',
                'color' => '#021B4A',
                'is_system' => true,
                'permissions' => $all,
            ],
            [
                'key' => 'admin',
                'name_ar' => 'مشرف عام',
                'name_en' => 'Admin',
                'description_ar' => 'صلاحية كاملة على العمليات اليومية باستثناء إدارة الأدوار',
                'description_en' => 'Full access to daily operations except role management',
                'color' => '#0A2F6B',
                'is_system' => true,
                'permissions' => array_filter($all, fn ($p) => !str_starts_with($p, 'roles.')),
            ],
            [
                'key' => 'manager',
                'name_ar' => 'مدير',
                'name_en' => 'Manager',
                'description_ar' => 'إدارة الجمعيات والعقارات والملاك والاجتماعات',
                'description_en' => 'Manage associations, properties, owners and meetings',
                'color' => '#0891b2',
                'is_system' => true,
                'permissions' => [
                    'dashboard.view',
                    'owners.view','owners.create','owners.update',
                    'associations.view','associations.create','associations.update',
                    'properties.view','properties.create','properties.update',
                    'units.view','units.create','units.update',
                    'contracts.view','contracts.create','contracts.update','contracts.print',
                    'meetings.view','meetings.create','meetings.update','meetings.attendance',
                    'votes.view','votes.create','votes.update',
                    'facilities.view','facilities.create','facilities.update','facilities.book','facilities.cancel_booking',
                    'maintenance.view','maintenance.create','maintenance.update','maintenance.update_status',
                    'vehicles.view','vehicles.create','vehicles.update',
                    'approvals.view',
                    'reports.view',
                    'activity_log.view',
                ],
            ],
            [
                'key' => 'accountant',
                'name_ar' => 'محاسب',
                'name_en' => 'Accountant',
                'description_ar' => 'الفواتير والسندات والتقارير المالية',
                'description_en' => 'Invoices, vouchers and financial reports',
                'color' => '#16a34a',
                'is_system' => true,
                'permissions' => [
                    'dashboard.view',
                    'owners.view',
                    'associations.view',
                    'properties.view',
                    'units.view',
                    'contracts.view','contracts.print',
                    'invoices.view','invoices.create','invoices.update','invoices.print',
                    'vouchers.view','vouchers.create','vouchers.update',
                    'reports.view','reports.export',
                ],
            ],
            [
                'key' => 'legal',
                'name_ar' => 'مدير قانوني',
                'name_en' => 'Legal Manager',
                'description_ar' => 'القضايا القانونية والعقود',
                'description_en' => 'Legal cases and contracts',
                'color' => '#dc2626',
                'is_system' => true,
                'permissions' => [
                    'dashboard.view',
                    'owners.view',
                    'associations.view',
                    'contracts.view','contracts.create','contracts.update','contracts.print',
                    'legal_cases.view','legal_cases.create','legal_cases.update',
                    'reports.view',
                ],
            ],
            [
                'key' => 'maintenance_supervisor',
                'name_ar' => 'مشرف صيانة',
                'name_en' => 'Maintenance Supervisor',
                'description_ar' => 'إدارة طلبات الصيانة وتحديث حالاتها',
                'description_en' => 'Manage maintenance requests and update statuses',
                'color' => '#f59e0b',
                'is_system' => true,
                'permissions' => [
                    'dashboard.view',
                    'associations.view','properties.view','units.view','owners.view',
                    'maintenance.view','maintenance.create','maintenance.update','maintenance.update_status',
                    'facilities.view',
                    'reports.view',
                ],
            ],
            [
                'key' => 'viewer',
                'name_ar' => 'مشاهد',
                'name_en' => 'Viewer',
                'description_ar' => 'عرض فقط دون أي تعديل',
                'description_en' => 'Read-only access across modules',
                'color' => '#64748b',
                'is_system' => true,
                'permissions' => array_filter($all, fn ($p) => str_ends_with($p, '.view')),
            ],
        ];
    }
}
