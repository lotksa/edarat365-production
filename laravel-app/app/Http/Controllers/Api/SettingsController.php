<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const ALLOWED_KEYS = [
        'general',
        'brand_identity',
        'contact_info',
        'social_media',
        'notifications',
        'mail',
        'sms',
        'turnstile',
        'integrations',
        'association_settings',
        'property_settings',
        'unit_settings',
        'owner_settings',
        'contract_settings',
        'meeting_settings',
        'legal_case_settings',
        'transaction_settings',
        'invoice_settings',
        'search_settings',
        'facility_settings',
    ];

    private const DEFAULTS = [
        'general' => [
            'site_name_ar' => 'إدارات 365',
            'site_name_en' => 'Edarat365',
            'site_logo' => '/brand/logo.png',
            'site_logo_dark' => '/brand/logo-dark.png',
            'site_icon' => '/brand/icon.png',
            'meta_description_ar' => 'منصة احترافية لإدارة اتحاد الملاك',
            'meta_description_en' => 'Professional HOA Management Platform',
            'meta_keywords_ar' => 'إدارة عقارات, اتحاد ملاك, فواتير',
            'meta_keywords_en' => 'property management, HOA, invoices',
            'default_language' => 'ar',
            'default_theme' => 'light',
            'copyright_ar' => '© 2026 إدارات 365. جميع الحقوق محفوظة.',
            'copyright_en' => '© 2026 Edarat365. All rights reserved.',
        ],
        'brand_identity' => [
            'login_logo_light' => '/brand/logo.png',
            'login_logo_dark' => '/brand/logo-dark.png',
            'sidebar_logo_light' => '/brand/logo.png',
            'sidebar_logo_dark' => '/brand/logo-dark.png',
            'sidebar_icon_light' => '/brand/icon.png',
            'sidebar_icon_dark' => '/brand/icon.png',
            'favicon' => '/brand/icon.png',
        ],
        'contact_info' => [
            'phones' => [
                ['label_ar' => 'هاتف', 'label_en' => 'Phone', 'number' => ''],
            ],
            'emails' => [
                ['label_ar' => 'البريد الإلكتروني', 'label_en' => 'Email', 'address' => ''],
            ],
            'address_ar' => '',
            'address_en' => '',
            'map_embed_url' => '',
        ],
        'social_media' => [
            'twitter' => '',
            'facebook' => '',
            'instagram' => '',
            'linkedin' => '',
            'youtube' => '',
            'tiktok' => '',
            'snapchat' => '',
            'whatsapp' => '',
        ],
        'notifications' => [
            'admin' => [
                'new_invoice' => true,
                'new_maintenance' => true,
                'new_meeting' => true,
                'new_legal_case' => true,
                'new_approval' => true,
                'system_alerts' => true,
            ],
            'owner' => [
                'new_invoice' => true,
                'invoice_reminder' => true,
                'meeting_notification' => true,
                'maintenance_update' => true,
                'system_announcements' => true,
            ],
            'channels' => [
                'in_app' => true,
                'email' => true,
                'sms' => false,
            ],
        ],
        'mail' => [
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => '',
            'smtp_password' => '',
            'from_address' => '',
            'from_name' => '',
        ],
        'sms' => [
            'provider' => '',
            'api_key' => '',
            'sender_name' => '',
            'api_url' => '',
        ],
        'turnstile' => [
            'enabled' => false,
            'site_key' => '',
            'secret_key' => '',
            'pages' => [
                'admin_login' => false,
                'owner_login' => false,
            ],
        ],
        'association_settings' => [
            'delete_protection' => true,
            'national_address_required' => false,
            'management_models' => [
                ['key' => 'self', 'name_ar' => 'الإدارة الذاتية', 'name_en' => 'Self Management', 'desc_ar' => 'يقوم الملاك بإدارة الجمعية بأنفسهم دون الاستعانة بجهة خارجية', 'desc_en' => 'Owners manage the association themselves without external parties', 'active' => true],
                ['key' => 'professional', 'name_ar' => 'الإدارة المهنية', 'name_en' => 'Professional Management', 'desc_ar' => 'يتم تعيين شركة إدارة عقارية متخصصة لإدارة الجمعية', 'desc_en' => 'A specialized property management company is hired', 'active' => true],
                ['key' => 'joint', 'name_ar' => 'الإدارة المشتركة', 'name_en' => 'Joint Management', 'desc_ar' => 'إدارة مشتركة بين الملاك وشركة إدارة عقارية بشكل تعاوني', 'desc_en' => 'Cooperative management between owners and a property management company', 'active' => true],
                ['key' => 'contractual', 'name_ar' => 'الإدارة التعاقدية', 'name_en' => 'Contractual Management', 'desc_ar' => 'إدارة بموجب عقد محدد المدة مع جهة إدارية مرخصة', 'desc_en' => 'Management under a fixed-term contract with a licensed entity', 'active' => true],
            ],
        ],
        'property_settings' => [
            'delete_protection' => true,
            'property_types' => [
                ['key' => 'residential', 'name_ar' => 'سكني', 'name_en' => 'Residential', 'active' => true],
                ['key' => 'commercial', 'name_ar' => 'تجاري', 'name_en' => 'Commercial', 'active' => true],
                ['key' => 'mixed', 'name_ar' => 'سكني وتجاري', 'name_en' => 'Mixed', 'active' => true],
                ['key' => 'land', 'name_ar' => 'أرض', 'name_en' => 'Land', 'active' => true],
            ],
            'deed_sources' => [
                ['key' => 'moj', 'name_ar' => 'وزارة العدل', 'name_en' => 'Ministry of Justice', 'active' => true],
                ['key' => 'rega', 'name_ar' => 'هيئة العقار', 'name_en' => 'Real Estate Authority', 'active' => true],
                ['key' => 'notary', 'name_ar' => 'كاتب العدل', 'name_en' => 'Notary', 'active' => true],
            ],
            'property_components' => [
                ['key' => 'floors', 'name_ar' => 'الأدوار', 'name_en' => 'Floors', 'active' => true],
                ['key' => 'elevators', 'name_ar' => 'المصاعد', 'name_en' => 'Elevators', 'active' => true],
                ['key' => 'parking', 'name_ar' => 'مواقف السيارات', 'name_en' => 'Parking Spaces', 'active' => true],
                ['key' => 'pools', 'name_ar' => 'المسابح', 'name_en' => 'Swimming Pools', 'active' => true],
                ['key' => 'gardens', 'name_ar' => 'الحدائق', 'name_en' => 'Gardens', 'active' => true],
                ['key' => 'guard_rooms', 'name_ar' => 'غرف الحراسة', 'name_en' => 'Guard Rooms', 'active' => true],
            ],
        ],
        'unit_settings' => [
            'delete_protection' => true,
            'rental_enabled' => false,
            'unit_types' => [
                ['key' => 'apartment', 'name_ar' => 'شقة', 'name_en' => 'Apartment', 'active' => true],
                ['key' => 'villa', 'name_ar' => 'فيلا', 'name_en' => 'Villa', 'active' => true],
                ['key' => 'office', 'name_ar' => 'مكتب', 'name_en' => 'Office', 'active' => true],
                ['key' => 'shop', 'name_ar' => 'محل تجاري', 'name_en' => 'Shop', 'active' => true],
                ['key' => 'warehouse', 'name_ar' => 'مستودع', 'name_en' => 'Warehouse', 'active' => true],
                ['key' => 'parking', 'name_ar' => 'موقف', 'name_en' => 'Parking', 'active' => true],
                ['key' => 'other', 'name_ar' => 'أخرى', 'name_en' => 'Other', 'active' => true],
            ],
            'unit_components' => [
                ['key' => 'bedrooms', 'name_ar' => 'غرف النوم', 'name_en' => 'Bedrooms', 'active' => true],
                ['key' => 'guest_room', 'name_ar' => 'مجلس الضيوف', 'name_en' => 'Guest Room', 'active' => true],
                ['key' => 'living_room', 'name_ar' => 'صالة معيشة', 'name_en' => 'Living Room', 'active' => true],
                ['key' => 'bathrooms', 'name_ar' => 'حمامات', 'name_en' => 'Bathrooms', 'active' => true],
                ['key' => 'kitchen', 'name_ar' => 'مطبخ', 'name_en' => 'Kitchen', 'active' => true],
                ['key' => 'ac_units', 'name_ar' => 'مكيفات', 'name_en' => 'AC Units', 'active' => true],
            ],
            'furnished_options' => [
                ['key' => 'unfurnished', 'name_ar' => 'بدون أثاث', 'name_en' => 'Unfurnished', 'active' => true],
                ['key' => 'semi_furnished', 'name_ar' => 'مؤثثة جزئياً', 'name_en' => 'Semi-Furnished', 'active' => true],
                ['key' => 'fully_furnished', 'name_ar' => 'مفروشة بالكامل', 'name_en' => 'Fully Furnished', 'active' => true],
            ],
            'payment_types' => [
                ['key' => 'monthly', 'name_ar' => 'شهري', 'name_en' => 'Monthly', 'active' => true],
                ['key' => 'quarterly', 'name_ar' => 'ربع سنوي', 'name_en' => 'Quarterly', 'active' => true],
                ['key' => 'semi_annual', 'name_ar' => 'نصف سنوي', 'name_en' => 'Semi-Annual', 'active' => true],
                ['key' => 'annual', 'name_ar' => 'سنوي', 'name_en' => 'Annual', 'active' => true],
            ],
            'contract_periods' => [
                ['key' => 'month', 'name_ar' => 'شهر', 'name_en' => 'Month', 'active' => true],
                ['key' => 'quarter', 'name_ar' => 'ربع سنوي', 'name_en' => 'Quarter', 'active' => true],
                ['key' => 'semi_annual', 'name_ar' => 'نصف سنوي', 'name_en' => 'Semi-Annual', 'active' => true],
                ['key' => 'annual', 'name_ar' => 'سنوي', 'name_en' => 'Annual', 'active' => true],
            ],
        ],
        'owner_settings' => [
            'delete_protection' => true,
            'national_address_required' => false,
        ],
        'contract_settings' => [
            'delete_protection' => true,
            'contract_natures' => [
                ['key' => 'residential_rent', 'name_ar' => 'إيجار سكني', 'name_en' => 'Residential Rent', 'active' => true],
                ['key' => 'commercial_rent', 'name_ar' => 'إيجار تجاري', 'name_en' => 'Commercial Rent', 'active' => true],
                ['key' => 'partnership', 'name_ar' => 'شراكة', 'name_en' => 'Partnership', 'active' => true],
            ],
            'contract_types' => [
                ['key' => 'residential', 'name_ar' => 'سكني', 'name_en' => 'Residential', 'active' => true],
                ['key' => 'commercial', 'name_ar' => 'تجاري', 'name_en' => 'Commercial', 'active' => true],
            ],
            'party_types' => [
                ['key' => 'individual', 'name_ar' => 'فرد', 'name_en' => 'Individual', 'active' => true],
                ['key' => 'company', 'name_ar' => 'شركة', 'name_en' => 'Company', 'active' => true],
                ['key' => 'institution', 'name_ar' => 'مؤسسة', 'name_en' => 'Institution', 'active' => true],
            ],
            'contract_periods' => [
                ['key' => 'monthly', 'name_ar' => 'شهري', 'name_en' => 'Monthly', 'active' => true],
                ['key' => 'quarterly', 'name_ar' => 'ربع سنوي', 'name_en' => 'Quarterly', 'active' => true],
                ['key' => 'semi_annual', 'name_ar' => 'نصف سنوي', 'name_en' => 'Semi-Annual', 'active' => true],
                ['key' => 'annual', 'name_ar' => 'سنوي', 'name_en' => 'Annual', 'active' => true],
            ],
            'payment_methods' => [
                ['key' => 'cash', 'name_ar' => 'نقدي', 'name_en' => 'Cash', 'active' => true],
                ['key' => 'bank_transfer', 'name_ar' => 'تحويل بنكي', 'name_en' => 'Bank Transfer', 'active' => true],
                ['key' => 'check', 'name_ar' => 'شيك', 'name_en' => 'Check', 'active' => true],
            ],
        ],
        'meeting_settings' => [
            'delete_protection' => true,
            'meeting_types' => [
                ['key' => 'general_assembly', 'name_ar' => 'جمعية عمومية', 'name_en' => 'General Assembly', 'active' => true],
                ['key' => 'board_meeting', 'name_ar' => 'اجتماع مجلس إدارة', 'name_en' => 'Board Meeting', 'active' => true],
                ['key' => 'emergency', 'name_ar' => 'اجتماع طارئ', 'name_en' => 'Emergency Meeting', 'active' => true],
            ],
            'resolution_types' => [
                ['key' => 'financial', 'name_ar' => 'مالي', 'name_en' => 'Financial', 'active' => true],
                ['key' => 'administrative', 'name_ar' => 'إداري', 'name_en' => 'Administrative', 'active' => true],
                ['key' => 'maintenance', 'name_ar' => 'صيانة', 'name_en' => 'Maintenance', 'active' => true],
            ],
            'agenda_types' => [
                ['key' => 'financial_report', 'name_ar' => 'التقرير المالي', 'name_en' => 'Financial Report', 'active' => true],
                ['key' => 'maintenance_plan', 'name_ar' => 'خطة الصيانة', 'name_en' => 'Maintenance Plan', 'active' => true],
                ['key' => 'general_discussion', 'name_ar' => 'مناقشة عامة', 'name_en' => 'General Discussion', 'active' => true],
            ],
            'notifications' => [
                'system_notification' => true,
                'email_notification' => true,
                'sms_notification' => false,
            ],
        ],
        'legal_case_settings' => [
            'delete_protection' => true,
            'case_types' => [
                ['key' => 'financial_dispute', 'name_ar' => 'نزاع مالي', 'name_en' => 'Financial Dispute', 'active' => true],
                ['key' => 'property_damage', 'name_ar' => 'ضرر بالعقار', 'name_en' => 'Property Damage', 'active' => true],
                ['key' => 'tenant_dispute', 'name_ar' => 'نزاع مستأجر', 'name_en' => 'Tenant Dispute', 'active' => true],
            ],
            'court_names' => [
                ['key' => 'general_court', 'name_ar' => 'المحكمة العامة', 'name_en' => 'General Court', 'active' => true],
                ['key' => 'commercial_court', 'name_ar' => 'المحكمة التجارية', 'name_en' => 'Commercial Court', 'active' => true],
                ['key' => 'enforcement_court', 'name_ar' => 'محكمة التنفيذ', 'name_en' => 'Enforcement Court', 'active' => true],
            ],
            'priorities' => [
                ['key' => 'urgent', 'name_ar' => 'عاجل', 'name_en' => 'Urgent', 'active' => true],
                ['key' => 'high', 'name_ar' => 'عالي', 'name_en' => 'High', 'active' => true],
                ['key' => 'medium', 'name_ar' => 'متوسط', 'name_en' => 'Medium', 'active' => true],
                ['key' => 'low', 'name_ar' => 'منخفض', 'name_en' => 'Low', 'active' => true],
            ],
            'case_statuses' => [
                ['key' => 'open', 'name_ar' => 'مفتوحة', 'name_en' => 'Open', 'active' => true],
                ['key' => 'pending', 'name_ar' => 'معلقة', 'name_en' => 'Pending', 'active' => true],
                ['key' => 'closed', 'name_ar' => 'مغلقة', 'name_en' => 'Closed', 'active' => true],
            ],
            'court_types' => [
                ['key' => 'commercial_court', 'name_ar' => 'المحكمة التجارية', 'name_en' => 'Commercial Court', 'active' => true],
                ['key' => 'arbitration_center', 'name_ar' => 'مركز التحكيم', 'name_en' => 'Arbitration Center', 'active' => true],
                ['key' => 'general_court', 'name_ar' => 'المحكمة العامة', 'name_en' => 'General Court', 'active' => true],
                ['key' => 'labor_court', 'name_ar' => 'المحكمة العمالية', 'name_en' => 'Labor Court', 'active' => true],
                ['key' => 'administrative_court', 'name_ar' => 'المحكمة الإدارية', 'name_en' => 'Administrative Court', 'active' => true],
            ],
            'verdict_statuses' => [
                ['key' => 'pending', 'name_ar' => 'قيد الانتظار', 'name_en' => 'Pending', 'active' => true],
                ['key' => 'in_favor', 'name_ar' => 'لصالحنا', 'name_en' => 'In Favor', 'active' => true],
                ['key' => 'against', 'name_ar' => 'ضدنا', 'name_en' => 'Against', 'active' => true],
                ['key' => 'settled', 'name_ar' => 'تسوية', 'name_en' => 'Settled', 'active' => true],
                ['key' => 'dismissed', 'name_ar' => 'رفض الدعوى', 'name_en' => 'Dismissed', 'active' => true],
            ],
        ],
        'transaction_settings' => [
            'delete_protection' => true,
            'parking_types' => [
                ['key' => 'indoor', 'name_ar' => 'داخلي', 'name_en' => 'Indoor', 'active' => true],
                ['key' => 'outdoor', 'name_ar' => 'خارجي', 'name_en' => 'Outdoor', 'active' => true],
                ['key' => 'covered', 'name_ar' => 'مغطى', 'name_en' => 'Covered', 'active' => true],
                ['key' => 'basement', 'name_ar' => 'سرداب', 'name_en' => 'Basement', 'active' => true],
            ],
            'car_types' => [
                ['key' => 'sedan', 'name_ar' => 'سيدان', 'name_en' => 'Sedan', 'active' => true],
                ['key' => 'suv', 'name_ar' => 'دفع رباعي', 'name_en' => 'SUV', 'active' => true],
                ['key' => 'van', 'name_ar' => 'فان', 'name_en' => 'Van', 'active' => true],
                ['key' => 'truck', 'name_ar' => 'شاحنة', 'name_en' => 'Truck', 'active' => true],
                ['key' => 'motorcycle', 'name_ar' => 'دراجة نارية', 'name_en' => 'Motorcycle', 'active' => true],
            ],
            'car_models' => [
                ['key' => 'toyota', 'name_ar' => 'تويوتا', 'name_en' => 'Toyota', 'active' => true],
                ['key' => 'hyundai', 'name_ar' => 'هيونداي', 'name_en' => 'Hyundai', 'active' => true],
                ['key' => 'nissan', 'name_ar' => 'نيسان', 'name_en' => 'Nissan', 'active' => true],
                ['key' => 'honda', 'name_ar' => 'هوندا', 'name_en' => 'Honda', 'active' => true],
                ['key' => 'kia', 'name_ar' => 'كيا', 'name_en' => 'Kia', 'active' => true],
                ['key' => 'bmw', 'name_ar' => 'بي إم دبليو', 'name_en' => 'BMW', 'active' => true],
                ['key' => 'mercedes', 'name_ar' => 'مرسيدس', 'name_en' => 'Mercedes', 'active' => true],
            ],
        ],
        'invoice_settings' => [
            'delete_protection' => true,
            'tax' => [
                'vat_enabled' => true,
                'vat_rate' => 15,
                'vat_number' => '',
                'commercial_registration' => '',
                'company_name_ar' => 'إدارات 365',
                'company_name_en' => 'Edarat365',
                'company_address_ar' => '',
                'company_address_en' => '',
                'zatca_enabled' => true,
                'zatca_phase' => '2',
                'zatca_legal_text_ar' => 'فاتورة ضريبية صادرة وفقاً لمتطلبات هيئة الزكاة والضريبة والجمارك',
                'zatca_legal_text_en' => 'Tax invoice issued in compliance with ZATCA requirements',
            ],
            'accounting_sync' => [
                'enabled' => false,
                'provider' => '',
            ],
            'invoice_types' => [
                ['key' => 'rent', 'name_ar' => 'إيجار', 'name_en' => 'Rent', 'active' => true],
                ['key' => 'maintenance', 'name_ar' => 'صيانة', 'name_en' => 'Maintenance', 'active' => true],
                ['key' => 'services', 'name_ar' => 'خدمات مشتركة', 'name_en' => 'Common Services', 'active' => true],
                ['key' => 'other', 'name_ar' => 'أخرى', 'name_en' => 'Other', 'active' => true],
            ],
            'payment_statuses' => [
                ['key' => 'pending', 'name_ar' => 'بانتظار الدفع', 'name_en' => 'Pending', 'active' => true],
                ['key' => 'paid', 'name_ar' => 'مدفوعة', 'name_en' => 'Paid', 'active' => true],
                ['key' => 'overdue', 'name_ar' => 'متأخرة', 'name_en' => 'Overdue', 'active' => true],
                ['key' => 'partial', 'name_ar' => 'جزئي', 'name_en' => 'Partial', 'active' => true],
            ],
            'payment_methods' => [
                ['key' => 'cash', 'name_ar' => 'نقدي', 'name_en' => 'Cash', 'active' => true],
                ['key' => 'bank_transfer', 'name_ar' => 'تحويل بنكي', 'name_en' => 'Bank Transfer', 'active' => true],
                ['key' => 'check', 'name_ar' => 'شيك', 'name_en' => 'Check', 'active' => true],
                ['key' => 'card', 'name_ar' => 'بطاقة', 'name_en' => 'Card', 'active' => true],
                ['key' => 'online', 'name_ar' => 'دفع إلكتروني', 'name_en' => 'Online Payment', 'active' => true],
            ],
        ],
        'integrations' => [
            'ejar' => [
                'enabled' => false,
                'api_url' => '',
                'api_key' => '',
                'api_secret' => '',
                'entity_id' => '',
            ],
            'payment' => [
                'enabled' => false,
                'provider' => 'hyperpay',
                'api_url' => '',
                'api_key' => '',
                'api_secret' => '',
                'entity_id' => '',
                'mode' => 'test',
            ],
            'daftra' => [
                'enabled' => false,
                'api_url' => '',
                'api_key' => '',
            ],
            'wafeq' => [
                'enabled' => false,
                'api_url' => '',
                'api_key' => '',
            ],
            'alameen' => [
                'enabled' => false,
                'api_url' => '',
                'api_key' => '',
                'api_secret' => '',
            ],
            'meetings' => [
                'teams' => [
                    'enabled' => false,
                    'client_id' => '',
                    'client_secret' => '',
                    'tenant_id' => '',
                ],
                'google_meet' => [
                    'enabled' => false,
                    'client_id' => '',
                    'client_secret' => '',
                ],
                'zoom' => [
                    'enabled' => false,
                    'api_key' => '',
                    'api_secret' => '',
                    'account_id' => '',
                ],
            ],
        ],
        'search_settings' => [
            'sections' => [
                'owners' => true,
                'associations' => true,
                'properties' => true,
                'units' => true,
                'contracts' => true,
                'meetings' => true,
                'votes' => true,
                'invoices' => true,
                'vouchers' => true,
                'maintenance' => true,
                'vehicles' => true,
                'legal_cases' => true,
            ],
            'ai_enabled' => false,
        ],
        'facility_settings' => [
            'facility_types' => [
                ['key' => 'playground', 'name_ar' => 'ملعب', 'name_en' => 'Playground', 'bookable' => true, 'active' => true],
                ['key' => 'swimming_pool', 'name_ar' => 'مسبح', 'name_en' => 'Swimming Pool', 'bookable' => true, 'active' => true],
                ['key' => 'gym', 'name_ar' => 'صالة رياضية', 'name_en' => 'Gym', 'bookable' => true, 'active' => true],
                ['key' => 'hall', 'name_ar' => 'قاعة متعددة الأغراض', 'name_en' => 'Multi-purpose Hall', 'bookable' => true, 'active' => true],
                ['key' => 'garden', 'name_ar' => 'حديقة', 'name_en' => 'Garden', 'bookable' => true, 'active' => true],
                ['key' => 'courtyard', 'name_ar' => 'فناء', 'name_en' => 'Courtyard', 'bookable' => false, 'active' => true],
                ['key' => 'corridor', 'name_ar' => 'ممر', 'name_en' => 'Corridor', 'bookable' => false, 'active' => true],
                ['key' => 'entrance', 'name_ar' => 'مدخل', 'name_en' => 'Entrance', 'bookable' => false, 'active' => true],
                ['key' => 'parking', 'name_ar' => 'موقف سيارات', 'name_en' => 'Parking Lot', 'bookable' => false, 'active' => true],
                ['key' => 'elevator', 'name_ar' => 'مصعد', 'name_en' => 'Elevator', 'bookable' => false, 'active' => true],
                ['key' => 'roof', 'name_ar' => 'سطح', 'name_en' => 'Roof', 'bookable' => true, 'active' => true],
            ],
            'max_booking_per_owner_per_day' => 1,
            'default_operating_hours_start' => '08:00',
            'default_operating_hours_end' => '22:00',
            'allow_owner_cancel' => true,
            'advance_booking_days' => 7,
        ],
    ];

    public function show(string $key): JsonResponse
    {
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            return response()->json(['message' => 'invalid_key'], 404);
        }

        $value = Setting::getByKey($key, self::DEFAULTS[$key] ?? []);

        return response()->json([
            'key' => $key,
            'value' => $value,
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            return response()->json(['message' => 'invalid_key'], 404);
        }

        $request->validate(['value' => ['required', 'array']]);

        $defaults = self::DEFAULTS[$key] ?? [];
        $current = Setting::getByKey($key, $defaults);
        $merged = array_replace_recursive($current, $request->input('value'));

        Setting::setByKey($key, $merged);

        return response()->json([
            'message' => 'ok',
            'key' => $key,
            'value' => $merged,
        ]);
    }
}
