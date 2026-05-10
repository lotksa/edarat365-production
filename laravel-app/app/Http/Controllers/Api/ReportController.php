<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Invoice, Voucher, Owner, Property, Unit, Contract, LegalCase, ParkingSpot, Vehicle, Meeting, Vote, MaintenanceRequest, User, Role, ActivityLog};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Throwable;

/**
 * Reports module — unified payload contract.
 *
 * Every report endpoint returns the SAME top-level shape so the frontend
 * can render it generically without per-report branches:
 *
 *   {
 *     "title":     { "ar": "...", "en": "..." },
 *     "subtitle":  { "ar": "...", "en": "..." },
 *     "generated_at": "2026-05-10T19:50:00Z",
 *     "year":      2026,
 *
 *     // 8–12 KPI cards. `format` ∈ number|currency|percent. `color` ∈
 *     // navy|blue|emerald|amber|red|indigo|purple|teal|slate.
 *     "stats": [ { "key", "label_ar", "label_en", "value",
 *                  "format"?, "suffix"?, "color"?, "icon"?,
 *                  "extra_ar"?, "extra_en"? }, ... ],
 *
 *     // 12-month series. `months` is ALWAYS 12 fixed entries, so the
 *     // frontend never has to pad. Each dataset has an array of 12
 *     // numbers aligned with `months`.
 *     "monthly": {
 *       "title_ar": "...", "title_en": "...",
 *       "year": 2026,
 *       "months":   [ { "index":1, "label_ar":"يناير", "label_en":"Jan" }, ... 12 entries ],
 *       "datasets": [ { "key", "label_ar", "label_en", "color", "values":[12 numbers] }, ... ]
 *     } | null,
 *
 *     // 0..N grouped breakdowns (status, type, ...) rendered as charts.
 *     // `type` ∈ doughnut|bar.
 *     "breakdowns": [
 *       { "key", "title_ar", "title_en", "type",
 *         "items": [ { "label_ar", "label_en", "value", "color"? }, ... ] }, ...
 *     ],
 *
 *     // Single tabular section. `format` ∈ text|currency|date|status.
 *     "table": {
 *       "title_ar", "title_en",
 *       "columns": [ { "key", "label_ar", "label_en", "format"? }, ... ],
 *       "rows":    [ ... assoc rows ... ]
 *     } | null
 *   }
 *
 * Every method is wrapped in try/catch so a single broken model column
 * does NOT break the whole report — we surface a structured "error"
 * response with empty stats instead of a 500.
 */
class ReportController extends Controller
{
    // ─────────────────────────────────────────────────────────────────
    // Shared palette & month labels
    // ─────────────────────────────────────────────────────────────────
    private const PALETTE = [
        '#021B4A', '#3b82f6', '#10b981', '#f59e0b', '#ef4444',
        '#6366f1', '#a855f7', '#14b8a6', '#ec4899', '#84cc16',
        '#0ea5e9', '#64748b',
    ];

    private const MONTHS_AR = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
    private const MONTHS_EN = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    /**
     * Build the canonical 12-entry months array.
     */
    private function monthsArray(): array
    {
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out[] = ['index' => $i + 1, 'label_ar' => self::MONTHS_AR[$i], 'label_en' => self::MONTHS_EN[$i]];
        }
        return $out;
    }

    /**
     * Pad a sparse "month → value" association into a 12-element array
     * indexed 0..11. Missing months become 0.
     */
    private function padMonthly(array $map): array
    {
        $values = array_fill(0, 12, 0);
        foreach ($map as $monthIndex => $value) {
            $i = (int) $monthIndex - 1;
            if ($i >= 0 && $i < 12) {
                $values[$i] = is_numeric($value) ? +$value : 0;
            }
        }
        return $values;
    }

    /**
     * Wrap every report endpoint in a uniform try/catch so a single
     * column rename or join error does not 500 the whole reports page.
     */
    private function safeReport(string $key, callable $build): JsonResponse
    {
        try {
            $payload = $build();
            $payload['generated_at'] = now()->toIso8601String();
            $payload['year']         = Carbon::now()->year;
            return response()->json($payload);
        } catch (Throwable $e) {
            Log::warning("ReportController::{$key} failed", [
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json([
                'title'        => ['ar' => '', 'en' => ''],
                'subtitle'     => ['ar' => '', 'en' => ''],
                'generated_at' => now()->toIso8601String(),
                'year'         => Carbon::now()->year,
                'stats'        => [],
                'monthly'      => null,
                'breakdowns'   => [],
                'table'        => null,
                'error' => [
                    'ar' => 'تعذر إنشاء التقرير حالياً، تم تسجيل المشكلة وسيتم معالجتها.',
                    'en' => 'The report could not be generated. The error has been logged.',
                ],
            ]);
        }
    }

    /**
     * Apply common ?from / ?to date filters to a query column.
     */
    private function applyDateRange($query, Request $request, string $column)
    {
        $from = $request->query('from');
        $to   = $request->query('to');
        if ($from) $query->whereDate($column, '>=', $from);
        if ($to)   $query->whereDate($column, '<=', $to);
        return $query;
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. FINANCIAL
    // ─────────────────────────────────────────────────────────────────
    public function financial(Request $request): JsonResponse
    {
        return $this->safeReport('financial', function () use ($request) {
            $year = Carbon::now()->year;

            $invoices = Invoice::query();
            $this->applyDateRange($invoices, $request, 'issue_date');

            $totalInvoices  = (clone $invoices)->count();
            $paidInvoices   = (clone $invoices)->where('status', 'paid')->count();
            $unpaidInvoices = (clone $invoices)->where('status', 'unpaid')->count();
            $cancelled      = (clone $invoices)->where('status', 'cancelled')->count();
            $totalRevenue   = (float) (clone $invoices)->where('status', 'paid')->sum('total_amount');
            $totalPending   = (float) (clone $invoices)->where('status', 'unpaid')->sum('total_amount');
            $totalTax       = (float) (clone $invoices)->sum('tax_amount');

            $totalReceipts  = (float) Voucher::where('type', 'receipt')->sum('amount');
            $totalPayments  = (float) Voucher::where('type', 'payment')->sum('amount');
            $receiptCount   = Voucher::where('type', 'receipt')->count();
            $paymentCount   = Voucher::where('type', 'payment')->count();

            $rows = Invoice::selectRaw("
                MONTH(COALESCE(issue_date, created_at)) AS m,
                SUM(CASE WHEN status='paid'   THEN total_amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN status='unpaid' THEN total_amount ELSE 0 END) AS unpaid_amount,
                COUNT(*) AS cnt
            ")->whereYear(DB::raw('COALESCE(issue_date, created_at)'), $year)
              ->groupByRaw('m')
              ->get();

            $paidByMonth = $unpaidByMonth = $countByMonth = [];
            foreach ($rows as $r) {
                $paidByMonth[$r->m]   = (float) $r->paid_amount;
                $unpaidByMonth[$r->m] = (float) $r->unpaid_amount;
                $countByMonth[$r->m]  = (int)   $r->cnt;
            }

            return [
                'title'    => ['ar' => 'التقرير المالي الشامل', 'en' => 'Financial Report'],
                'subtitle' => ['ar' => 'الفواتير والسندات والإيرادات', 'en' => 'Invoices, vouchers and revenue'],

                'stats' => [
                    ['key' => 'total_invoices',  'label_ar' => 'إجمالي الفواتير',  'label_en' => 'Total Invoices', 'value' => $totalInvoices,  'format' => 'number',   'color' => 'navy',    'icon' => 'invoice'],
                    ['key' => 'paid_invoices',   'label_ar' => 'فواتير مدفوعة',     'label_en' => 'Paid Invoices',  'value' => $paidInvoices,    'format' => 'number',   'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'unpaid_invoices', 'label_ar' => 'فواتير غير مدفوعة', 'label_en' => 'Unpaid',         'value' => $unpaidInvoices,  'format' => 'number',   'color' => 'amber',   'icon' => 'clock'],
                    ['key' => 'cancelled',       'label_ar' => 'فواتير ملغاة',       'label_en' => 'Cancelled',      'value' => $cancelled,       'format' => 'number',   'color' => 'slate',   'icon' => 'cancel'],
                    ['key' => 'total_revenue',   'label_ar' => 'إجمالي الإيرادات',  'label_en' => 'Revenue',        'value' => $totalRevenue,   'format' => 'currency', 'color' => 'emerald', 'icon' => 'dollar'],
                    ['key' => 'total_pending',   'label_ar' => 'مبالغ معلقة',        'label_en' => 'Pending',        'value' => $totalPending,   'format' => 'currency', 'color' => 'red',     'icon' => 'alert'],
                    ['key' => 'total_tax',       'label_ar' => 'إجمالي الضريبة',     'label_en' => 'Tax',            'value' => $totalTax,       'format' => 'currency', 'color' => 'indigo',  'icon' => 'percent'],
                    ['key' => 'receipts',        'label_ar' => 'سندات القبض',        'label_en' => 'Receipts',       'value' => $receiptCount,    'format' => 'number',   'color' => 'emerald', 'icon' => 'voucher', 'extra_ar' => number_format($totalReceipts, 2) . ' ر.س', 'extra_en' => number_format($totalReceipts, 2) . ' SAR'],
                    ['key' => 'payments',        'label_ar' => 'سندات الصرف',        'label_en' => 'Payments',       'value' => $paymentCount,    'format' => 'number',   'color' => 'red',     'icon' => 'voucher', 'extra_ar' => number_format($totalPayments, 2) . ' ر.س', 'extra_en' => number_format($totalPayments, 2) . ' SAR'],
                ],

                'monthly' => [
                    'title_ar' => 'الإيرادات والمبالغ المعلقة شهرياً',
                    'title_en' => 'Monthly Revenue & Pending',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'paid',   'label_ar' => 'إيرادات مدفوعة', 'label_en' => 'Paid Amount',   'color' => '#10b981', 'values' => $this->padMonthly($paidByMonth)],
                        ['key' => 'unpaid', 'label_ar' => 'مبالغ معلقة',     'label_en' => 'Unpaid Amount', 'color' => '#f59e0b', 'values' => $this->padMonthly($unpaidByMonth)],
                        ['key' => 'count',  'label_ar' => 'عدد الفواتير',    'label_en' => 'Invoice Count', 'color' => '#021B4A', 'values' => $this->padMonthly($countByMonth)],
                    ],
                ],

                'breakdowns' => [
                    [
                        'key' => 'invoice_status',
                        'title_ar' => 'الفواتير حسب الحالة',
                        'title_en' => 'Invoices by Status',
                        'type' => 'doughnut',
                        'items' => [
                            ['label_ar' => 'مدفوعة',    'label_en' => 'Paid',      'value' => $paidInvoices,   'color' => '#10b981'],
                            ['label_ar' => 'غير مدفوعة', 'label_en' => 'Unpaid',    'value' => $unpaidInvoices, 'color' => '#f59e0b'],
                            ['label_ar' => 'ملغاة',     'label_en' => 'Cancelled', 'value' => $cancelled,      'color' => '#ef4444'],
                        ],
                    ],
                    [
                        'key' => 'cash_flow',
                        'title_ar' => 'التدفقات النقدية',
                        'title_en' => 'Cash Flow',
                        'type' => 'bar',
                        'items' => [
                            ['label_ar' => 'مقبوضات', 'label_en' => 'Receipts', 'value' => $totalReceipts, 'color' => '#10b981'],
                            ['label_ar' => 'مدفوعات', 'label_en' => 'Payments', 'value' => $totalPayments, 'color' => '#ef4444'],
                        ],
                    ],
                ],

                'table' => [
                    'title_ar' => 'أحدث الفواتير',
                    'title_en' => 'Recent Invoices',
                    'columns' => [
                        ['key' => 'invoice_number', 'label_ar' => 'رقم الفاتورة', 'label_en' => 'Invoice #', 'format' => 'text'],
                        ['key' => 'owner_name',     'label_ar' => 'المالك',       'label_en' => 'Owner',     'format' => 'text'],
                        ['key' => 'total_amount',  'label_ar' => 'المبلغ',        'label_en' => 'Amount',    'format' => 'currency'],
                        ['key' => 'status',         'label_ar' => 'الحالة',       'label_en' => 'Status',    'format' => 'status'],
                        ['key' => 'issue_date',     'label_ar' => 'تاريخ الإصدار', 'label_en' => 'Issued',    'format' => 'date'],
                    ],
                    'rows' => Invoice::with('owner')->latest()->take(15)->get()->map(fn ($i) => [
                        'invoice_number' => $i->invoice_number,
                        'owner_name'     => $i->owner?->full_name ?? '-',
                        'total_amount'   => (float) $i->total_amount,
                        'status'         => $i->status,
                        'issue_date'     => optional($i->issue_date)->format('Y-m-d') ?? optional($i->created_at)->format('Y-m-d'),
                    ])->values(),
                ],
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. OWNERS
    // ─────────────────────────────────────────────────────────────────
    public function owners(Request $request): JsonResponse
    {
        return $this->safeReport('owners', function () use ($request) {
            $year = Carbon::now()->year;

            $total       = Owner::count();
            $active      = Owner::where('status', 'active')->count();
            $inactive    = Owner::where('status', '!=', 'active')->count();
            $thisMonth   = Owner::whereMonth('created_at', Carbon::now()->month)
                                ->whereYear('created_at', $year)->count();
            $withUnits   = Owner::whereHas('units')->count();
            $withoutUnits = max(0, $total - $withUnits);

            $monthlyRows = Owner::selectRaw('MONTH(created_at) AS m, COUNT(*) AS cnt')
                ->whereYear('created_at', $year)
                ->groupByRaw('m')
                ->get()
                ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                ->all();

            $byProperty = collect();
            try {
                if (Schema::hasTable('owner_unit')) {
                    $byProperty = DB::table('owners')
                        ->join('owner_unit', 'owners.id', '=', 'owner_unit.owner_id')
                        ->join('units', 'owner_unit.unit_id', '=', 'units.id')
                        ->join('properties', 'units.property_id', '=', 'properties.id')
                        ->select('properties.name', DB::raw('COUNT(DISTINCT owners.id) as cnt'))
                        ->groupBy('properties.name')
                        ->orderByDesc('cnt')
                        ->take(10)
                        ->get();
                }
            } catch (Throwable) { /* relation table may differ — leave empty */ }

            $recentOwners = Owner::latest()->take(15)->get()->map(fn ($o) => [
                'full_name'  => $o->full_name,
                'phone'      => $o->phone,
                'status'     => $o->status,
                'created_at' => optional($o->created_at)->format('Y-m-d'),
            ])->values();

            $breakdowns = [
                [
                    'key' => 'owner_status',
                    'title_ar' => 'الملاك حسب الحالة',
                    'title_en' => 'Owners by Status',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'نشط',     'label_en' => 'Active',   'value' => $active,   'color' => '#10b981'],
                        ['label_ar' => 'غير نشط', 'label_en' => 'Inactive', 'value' => $inactive, 'color' => '#94a3b8'],
                    ],
                ],
                [
                    'key' => 'owner_units_coverage',
                    'title_ar' => 'تغطية الوحدات',
                    'title_en' => 'Units Coverage',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'يملكون وحدات', 'label_en' => 'With Units',    'value' => $withUnits,    'color' => '#021B4A'],
                        ['label_ar' => 'بدون وحدات',   'label_en' => 'Without Units', 'value' => $withoutUnits, 'color' => '#f59e0b'],
                    ],
                ],
            ];

            if ($byProperty->count() > 0) {
                $breakdowns[] = [
                    'key' => 'by_property',
                    'title_ar' => 'الملاك حسب العقار (أعلى 10)',
                    'title_en' => 'Owners by Property (Top 10)',
                    'type' => 'bar',
                    'items' => $byProperty->map(fn ($r, $i) => [
                        'label_ar' => $r->name ?? '-',
                        'label_en' => $r->name ?? '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            return [
                'title'    => ['ar' => 'تقرير الملاك', 'en' => 'Owners Report'],
                'subtitle' => ['ar' => 'بيانات الملاك والتسجيلات والتوزيع', 'en' => 'Owner data, registrations and distribution'],

                'stats' => [
                    ['key' => 'total',          'label_ar' => 'إجمالي الملاك',     'label_en' => 'Total Owners',    'value' => $total,        'format' => 'number', 'color' => 'navy',    'icon' => 'users'],
                    ['key' => 'active',         'label_ar' => 'نشط',                'label_en' => 'Active',          'value' => $active,       'format' => 'number', 'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'inactive',       'label_ar' => 'غير نشط',            'label_en' => 'Inactive',        'value' => $inactive,     'format' => 'number', 'color' => 'red',     'icon' => 'cancel'],
                    ['key' => 'new_this_month', 'label_ar' => 'جدد هذا الشهر',     'label_en' => 'New This Month',  'value' => $thisMonth,    'format' => 'number', 'color' => 'blue',    'icon' => 'plus'],
                    ['key' => 'with_units',     'label_ar' => 'يملكون وحدات',      'label_en' => 'With Units',      'value' => $withUnits,    'format' => 'number', 'color' => 'indigo',  'icon' => 'grid'],
                    ['key' => 'without_units',  'label_ar' => 'بدون وحدات',         'label_en' => 'Without Units',   'value' => $withoutUnits, 'format' => 'number', 'color' => 'amber',   'icon' => 'alert'],
                ],

                'monthly' => [
                    'title_ar' => 'تسجيلات الملاك شهرياً',
                    'title_en' => 'Owner Registrations',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'count', 'label_ar' => 'تسجيلات جديدة', 'label_en' => 'New Owners', 'color' => '#021B4A', 'values' => $this->padMonthly($monthlyRows)],
                    ],
                ],

                'breakdowns' => $breakdowns,

                'table' => [
                    'title_ar' => 'أحدث الملاك المسجلين',
                    'title_en' => 'Recent Owners',
                    'columns' => [
                        ['key' => 'full_name',  'label_ar' => 'الاسم',          'label_en' => 'Name',       'format' => 'text'],
                        ['key' => 'phone',      'label_ar' => 'الجوال',         'label_en' => 'Phone',      'format' => 'text'],
                        ['key' => 'status',     'label_ar' => 'الحالة',         'label_en' => 'Status',     'format' => 'status'],
                        ['key' => 'created_at', 'label_ar' => 'تاريخ التسجيل', 'label_en' => 'Registered', 'format' => 'date'],
                    ],
                    'rows' => $recentOwners,
                ],
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. PROPERTIES + UNITS
    // ─────────────────────────────────────────────────────────────────
    public function properties(Request $request): JsonResponse
    {
        return $this->safeReport('properties', function () use ($request) {
            $year = Carbon::now()->year;

            $totalProperties  = Property::count();
            $activeProperties = Property::where('status', 'active')->count();
            $totalUnits       = Unit::count();
            $occupiedUnits    = Unit::where('status', 'occupied')->count();
            $vacantUnits      = Unit::whereIn('status', ['vacant', 'available'])->count();
            $occupancyRate    = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

            $unitsByType = Unit::selectRaw('unit_type AS type, COUNT(*) AS cnt')
                ->groupBy('unit_type')
                ->orderByDesc('cnt')
                ->get();

            $propsByType = Property::selectRaw('type, COUNT(*) AS cnt')
                ->groupBy('type')
                ->orderByDesc('cnt')
                ->get();

            $unitsByProperty = Unit::join('properties', 'units.property_id', '=', 'properties.id')
                ->selectRaw('properties.name, COUNT(units.id) AS total, SUM(CASE WHEN units.status="occupied" THEN 1 ELSE 0 END) AS occupied')
                ->groupBy('properties.name')
                ->orderByDesc('total')
                ->take(10)
                ->get();

            $monthlyRows = Property::selectRaw('MONTH(created_at) AS m, COUNT(*) AS cnt')
                ->whereYear('created_at', $year)
                ->groupByRaw('m')
                ->get()
                ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                ->all();

            $breakdowns = [
                [
                    'key' => 'unit_status',
                    'title_ar' => 'الوحدات حسب حالة الإشغال',
                    'title_en' => 'Units by Occupancy Status',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'مشغولة', 'label_en' => 'Occupied', 'value' => $occupiedUnits,             'color' => '#10b981'],
                        ['label_ar' => 'شاغرة',  'label_en' => 'Vacant',   'value' => $vacantUnits,               'color' => '#f59e0b'],
                        ['label_ar' => 'أخرى',  'label_en' => 'Other',    'value' => max(0, $totalUnits - $occupiedUnits - $vacantUnits), 'color' => '#94a3b8'],
                    ],
                ],
            ];

            if ($unitsByType->count() > 0) {
                $breakdowns[] = [
                    'key' => 'units_by_type',
                    'title_ar' => 'الوحدات حسب النوع',
                    'title_en' => 'Units by Type',
                    'type' => 'bar',
                    'items' => $unitsByType->map(fn ($r, $i) => [
                        'label_ar' => $r->type ?: '-',
                        'label_en' => $r->type ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            if ($propsByType->count() > 0) {
                $breakdowns[] = [
                    'key' => 'properties_by_type',
                    'title_ar' => 'العقارات حسب النوع',
                    'title_en' => 'Properties by Type',
                    'type' => 'doughnut',
                    'items' => $propsByType->map(fn ($r, $i) => [
                        'label_ar' => $r->type ?: '-',
                        'label_en' => $r->type ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            return [
                'title'    => ['ar' => 'تقرير العقارات والوحدات', 'en' => 'Properties & Units Report'],
                'subtitle' => ['ar' => 'نسب الإشغال وتوزيع الوحدات', 'en' => 'Occupancy and unit distribution'],

                'stats' => [
                    ['key' => 'properties',     'label_ar' => 'إجمالي العقارات',  'label_en' => 'Properties',     'value' => $totalProperties,  'format' => 'number',  'color' => 'navy',    'icon' => 'building'],
                    ['key' => 'active_props',   'label_ar' => 'عقارات نشطة',      'label_en' => 'Active',         'value' => $activeProperties, 'format' => 'number',  'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'total_units',    'label_ar' => 'إجمالي الوحدات',   'label_en' => 'Total Units',    'value' => $totalUnits,       'format' => 'number',  'color' => 'blue',    'icon' => 'grid'],
                    ['key' => 'occupied_units', 'label_ar' => 'وحدات مشغولة',     'label_en' => 'Occupied Units', 'value' => $occupiedUnits,    'format' => 'number',  'color' => 'emerald', 'icon' => 'home'],
                    ['key' => 'vacant_units',   'label_ar' => 'وحدات شاغرة',      'label_en' => 'Vacant Units',   'value' => $vacantUnits,      'format' => 'number',  'color' => 'amber',   'icon' => 'clock'],
                    ['key' => 'occupancy_rate', 'label_ar' => 'نسبة الإشغال',     'label_en' => 'Occupancy Rate', 'value' => $occupancyRate,    'format' => 'percent', 'color' => 'indigo',  'icon' => 'percent'],
                ],

                'monthly' => [
                    'title_ar' => 'إضافة العقارات شهرياً',
                    'title_en' => 'Properties Added Per Month',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'count', 'label_ar' => 'عقارات جديدة', 'label_en' => 'New Properties', 'color' => '#021B4A', 'values' => $this->padMonthly($monthlyRows)],
                    ],
                ],

                'breakdowns' => $breakdowns,

                'table' => $unitsByProperty->count() > 0 ? [
                    'title_ar' => 'الإشغال حسب العقار',
                    'title_en' => 'Occupancy by Property',
                    'columns' => [
                        ['key' => 'name',     'label_ar' => 'العقار',           'label_en' => 'Property',  'format' => 'text'],
                        ['key' => 'total',    'label_ar' => 'إجمالي الوحدات',  'label_en' => 'Units',     'format' => 'number'],
                        ['key' => 'occupied', 'label_ar' => 'مشغولة',           'label_en' => 'Occupied',  'format' => 'number'],
                        ['key' => 'rate',     'label_ar' => 'نسبة الإشغال',     'label_en' => 'Occupancy', 'format' => 'percent'],
                    ],
                    'rows' => $unitsByProperty->map(fn ($r) => [
                        'name'     => $r->name ?? '-',
                        'total'    => (int) $r->total,
                        'occupied' => (int) $r->occupied,
                        'rate'     => $r->total > 0 ? round(($r->occupied / $r->total) * 100, 1) : 0,
                    ])->values(),
                ] : null,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. CONTRACTS
    // ─────────────────────────────────────────────────────────────────
    public function contracts(Request $request): JsonResponse
    {
        return $this->safeReport('contracts', function () use ($request) {
            $year = Carbon::now()->year;

            $total      = Contract::count();
            $active     = Contract::where('status', 'active')->count();
            $expired    = Contract::where('status', 'expired')->count();
            $terminated = Contract::where('status', 'terminated')->count();
            $draft      = Contract::where('status', 'draft')->count();

            $expiringIn30 = Contract::where('status', 'active')
                ->whereBetween('end_date', [Carbon::now(), Carbon::now()->addDays(30)])
                ->count();

            $monthlyRows = Contract::selectRaw('MONTH(COALESCE(start_date, created_at)) AS m, COUNT(*) AS cnt')
                ->whereYear(DB::raw('COALESCE(start_date, created_at)'), $year)
                ->groupByRaw('m')
                ->get()
                ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                ->all();

            $byNature = Contract::selectRaw('contract_nature, COUNT(*) AS cnt')
                ->whereNotNull('contract_nature')
                ->groupBy('contract_nature')
                ->orderByDesc('cnt')
                ->get();

            $expiringSoon = Contract::with(['owner', 'property'])
                ->where('status', 'active')
                ->whereNotNull('end_date')
                ->where('end_date', '>=', Carbon::now())
                ->orderBy('end_date')
                ->take(15)
                ->get()
                ->map(fn ($c) => [
                    'contract_number' => $c->contract_number ?? '-',
                    'owner_name'      => $c->owner?->full_name ?? $c->party2_name ?? '-',
                    'property_name'   => $c->property?->name ?? '-',
                    'end_date'        => optional($c->end_date)->format('Y-m-d'),
                    'days_left'       => $c->end_date ? (int) Carbon::now()->diffInDays($c->end_date, false) : null,
                ]);

            $breakdowns = [
                [
                    'key' => 'contract_status',
                    'title_ar' => 'العقود حسب الحالة',
                    'title_en' => 'Contracts by Status',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'نشط',   'label_en' => 'Active',     'value' => $active,     'color' => '#10b981'],
                        ['label_ar' => 'منتهي', 'label_en' => 'Expired',    'value' => $expired,    'color' => '#ef4444'],
                        ['label_ar' => 'ملغي',  'label_en' => 'Terminated', 'value' => $terminated, 'color' => '#94a3b8'],
                        ['label_ar' => 'مسودة', 'label_en' => 'Draft',      'value' => $draft,      'color' => '#3b82f6'],
                    ],
                ],
            ];

            if ($byNature->count() > 0) {
                $breakdowns[] = [
                    'key' => 'by_nature',
                    'title_ar' => 'العقود حسب الطبيعة',
                    'title_en' => 'Contracts by Nature',
                    'type' => 'bar',
                    'items' => $byNature->map(fn ($r, $i) => [
                        'label_ar' => $r->contract_nature ?: '-',
                        'label_en' => $r->contract_nature ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            return [
                'title'    => ['ar' => 'تقرير العقود', 'en' => 'Contracts Report'],
                'subtitle' => ['ar' => 'العقود النشطة والمنتهية وتواريخ التجديد', 'en' => 'Active, expired and renewal dates'],

                'stats' => [
                    ['key' => 'total',        'label_ar' => 'إجمالي العقود',    'label_en' => 'Total',          'value' => $total,        'format' => 'number', 'color' => 'navy',    'icon' => 'file'],
                    ['key' => 'active',       'label_ar' => 'نشطة',             'label_en' => 'Active',         'value' => $active,       'format' => 'number', 'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'expired',      'label_ar' => 'منتهية',           'label_en' => 'Expired',        'value' => $expired,      'format' => 'number', 'color' => 'red',     'icon' => 'cancel'],
                    ['key' => 'expiring_30',  'label_ar' => 'ينتهي خلال 30 يوم','label_en' => 'Expiring Soon',  'value' => $expiringIn30, 'format' => 'number', 'color' => 'amber',   'icon' => 'alert'],
                    ['key' => 'terminated',   'label_ar' => 'ملغاة',             'label_en' => 'Terminated',     'value' => $terminated,   'format' => 'number', 'color' => 'slate',   'icon' => 'cancel'],
                    ['key' => 'draft',        'label_ar' => 'مسودات',            'label_en' => 'Drafts',         'value' => $draft,        'format' => 'number', 'color' => 'blue',    'icon' => 'file'],
                ],

                'monthly' => [
                    'title_ar' => 'العقود المُبرمة شهرياً',
                    'title_en' => 'Contracts Signed Monthly',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'count', 'label_ar' => 'عقود جديدة', 'label_en' => 'New Contracts', 'color' => '#021B4A', 'values' => $this->padMonthly($monthlyRows)],
                    ],
                ],

                'breakdowns' => $breakdowns,

                'table' => $expiringSoon->count() > 0 ? [
                    'title_ar' => 'عقود قريبة الانتهاء',
                    'title_en' => 'Expiring Contracts',
                    'columns' => [
                        ['key' => 'contract_number', 'label_ar' => 'رقم العقد',     'label_en' => 'Contract #',  'format' => 'text'],
                        ['key' => 'owner_name',      'label_ar' => 'المالك',        'label_en' => 'Owner',       'format' => 'text'],
                        ['key' => 'property_name',   'label_ar' => 'العقار',        'label_en' => 'Property',    'format' => 'text'],
                        ['key' => 'end_date',        'label_ar' => 'تاريخ الانتهاء', 'label_en' => 'End Date',   'format' => 'date'],
                        ['key' => 'days_left',       'label_ar' => 'أيام متبقية',    'label_en' => 'Days Left',   'format' => 'number'],
                    ],
                    'rows' => $expiringSoon->values(),
                ] : null,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. MAINTENANCE
    // ─────────────────────────────────────────────────────────────────
    public function maintenance(Request $request): JsonResponse
    {
        return $this->safeReport('maintenance', function () use ($request) {
            $year = Carbon::now()->year;

            $total     = MaintenanceRequest::count();
            $pending   = MaintenanceRequest::where('status', 'pending')->count();
            $progress  = MaintenanceRequest::where('status', 'in_progress')->count();
            $completed = MaintenanceRequest::where('status', 'completed')->count();
            $cancelled = MaintenanceRequest::where('status', 'cancelled')->count();

            $monthlyRows = MaintenanceRequest::selectRaw('MONTH(created_at) AS m, COUNT(*) AS cnt')
                ->whereYear('created_at', $year)
                ->groupByRaw('m')
                ->get()
                ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                ->all();

            $byPriority = MaintenanceRequest::selectRaw('priority, COUNT(*) AS cnt')
                ->whereNotNull('priority')
                ->groupBy('priority')
                ->orderByDesc('cnt')
                ->get();

            $recent = MaintenanceRequest::latest()->take(15)->get()->map(fn ($r) => [
                'title'      => $r->title ?? '-',
                'status'     => $r->status,
                'priority'   => $r->priority ?? '-',
                'created_at' => optional($r->created_at)->format('Y-m-d'),
            ]);

            $breakdowns = [
                [
                    'key' => 'maintenance_status',
                    'title_ar' => 'الطلبات حسب الحالة',
                    'title_en' => 'Requests by Status',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'معلق',       'label_en' => 'Pending',     'value' => $pending,   'color' => '#f59e0b'],
                        ['label_ar' => 'قيد التنفيذ', 'label_en' => 'In Progress', 'value' => $progress,  'color' => '#3b82f6'],
                        ['label_ar' => 'مكتمل',      'label_en' => 'Completed',   'value' => $completed, 'color' => '#10b981'],
                        ['label_ar' => 'ملغي',       'label_en' => 'Cancelled',   'value' => $cancelled, 'color' => '#94a3b8'],
                    ],
                ],
            ];

            if ($byPriority->count() > 0) {
                $breakdowns[] = [
                    'key' => 'by_priority',
                    'title_ar' => 'الطلبات حسب الأولوية',
                    'title_en' => 'Requests by Priority',
                    'type' => 'bar',
                    'items' => $byPriority->map(fn ($r, $i) => [
                        'label_ar' => $r->priority ?: '-',
                        'label_en' => $r->priority ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            return [
                'title'    => ['ar' => 'تقرير الصيانة', 'en' => 'Maintenance Report'],
                'subtitle' => ['ar' => 'طلبات الصيانة حسب الحالة والشهر', 'en' => 'Maintenance requests by status and month'],

                'stats' => [
                    ['key' => 'total',       'label_ar' => 'إجمالي الطلبات', 'label_en' => 'Total',       'value' => $total,     'format' => 'number', 'color' => 'navy',    'icon' => 'wrench'],
                    ['key' => 'pending',     'label_ar' => 'معلق',           'label_en' => 'Pending',     'value' => $pending,   'format' => 'number', 'color' => 'amber',   'icon' => 'clock'],
                    ['key' => 'in_progress', 'label_ar' => 'قيد التنفيذ',    'label_en' => 'In Progress', 'value' => $progress,  'format' => 'number', 'color' => 'blue',    'icon' => 'wrench'],
                    ['key' => 'completed',   'label_ar' => 'مكتمل',          'label_en' => 'Completed',   'value' => $completed, 'format' => 'number', 'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'cancelled',   'label_ar' => 'ملغي',           'label_en' => 'Cancelled',   'value' => $cancelled, 'format' => 'number', 'color' => 'red',     'icon' => 'cancel'],
                ],

                'monthly' => [
                    'title_ar' => 'طلبات الصيانة شهرياً',
                    'title_en' => 'Maintenance Requests',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'count', 'label_ar' => 'عدد الطلبات', 'label_en' => 'Request Count', 'color' => '#021B4A', 'values' => $this->padMonthly($monthlyRows)],
                    ],
                ],

                'breakdowns' => $breakdowns,

                'table' => $recent->count() > 0 ? [
                    'title_ar' => 'أحدث طلبات الصيانة',
                    'title_en' => 'Recent Requests',
                    'columns' => [
                        ['key' => 'title',      'label_ar' => 'الطلب',     'label_en' => 'Request',  'format' => 'text'],
                        ['key' => 'priority',   'label_ar' => 'الأولوية',  'label_en' => 'Priority', 'format' => 'text'],
                        ['key' => 'status',     'label_ar' => 'الحالة',    'label_en' => 'Status',   'format' => 'status'],
                        ['key' => 'created_at', 'label_ar' => 'التاريخ',   'label_en' => 'Date',     'format' => 'date'],
                    ],
                    'rows' => $recent->values(),
                ] : null,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 6. LEGAL CASES
    // ─────────────────────────────────────────────────────────────────
    public function legal(Request $request): JsonResponse
    {
        return $this->safeReport('legal', function () use ($request) {
            $year = Carbon::now()->year;

            $total   = LegalCase::count();
            $open    = LegalCase::where('status', 'open')->count();
            $pending = LegalCase::where('status', 'pending')->count();
            $closed  = LegalCase::where('status', 'closed')->count();

            $monthlyRows = LegalCase::selectRaw('MONTH(COALESCE(filing_date, created_at)) AS m, COUNT(*) AS cnt')
                ->whereYear(DB::raw('COALESCE(filing_date, created_at)'), $year)
                ->groupByRaw('m')
                ->get()
                ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                ->all();

            $byType = LegalCase::selectRaw('case_type, COUNT(*) AS cnt')
                ->whereNotNull('case_type')
                ->groupBy('case_type')
                ->orderByDesc('cnt')
                ->get();

            $byPriority = LegalCase::selectRaw('priority, COUNT(*) AS cnt')
                ->whereNotNull('priority')
                ->groupBy('priority')
                ->orderByDesc('cnt')
                ->get();

            $recentCases = LegalCase::with('owner')->latest()->take(15)->get()->map(fn ($c) => [
                'case_number' => $c->case_number ?? '-',
                'case_type'   => $c->case_type ?? '-',
                'owner_name'  => $c->owner?->full_name ?? '-',
                'status'      => $c->status,
                'filing_date' => optional($c->filing_date)->format('Y-m-d') ?? optional($c->created_at)->format('Y-m-d'),
            ]);

            $breakdowns = [
                [
                    'key' => 'case_status',
                    'title_ar' => 'القضايا حسب الحالة',
                    'title_en' => 'Cases by Status',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'مفتوحة', 'label_en' => 'Open',    'value' => $open,    'color' => '#10b981'],
                        ['label_ar' => 'معلقة',  'label_en' => 'Pending', 'value' => $pending, 'color' => '#f59e0b'],
                        ['label_ar' => 'مغلقة',  'label_en' => 'Closed',  'value' => $closed,  'color' => '#94a3b8'],
                    ],
                ],
            ];

            if ($byType->count() > 0) {
                $breakdowns[] = [
                    'key' => 'by_type',
                    'title_ar' => 'القضايا حسب النوع',
                    'title_en' => 'Cases by Type',
                    'type' => 'bar',
                    'items' => $byType->map(fn ($r, $i) => [
                        'label_ar' => $r->case_type ?: '-',
                        'label_en' => $r->case_type ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            if ($byPriority->count() > 0) {
                $breakdowns[] = [
                    'key' => 'by_priority',
                    'title_ar' => 'القضايا حسب الأولوية',
                    'title_en' => 'Cases by Priority',
                    'type' => 'doughnut',
                    'items' => $byPriority->map(fn ($r, $i) => [
                        'label_ar' => $r->priority ?: '-',
                        'label_en' => $r->priority ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            return [
                'title'    => ['ar' => 'تقرير القضايا', 'en' => 'Legal Cases Report'],
                'subtitle' => ['ar' => 'القضايا حسب النوع والحالة والأولوية', 'en' => 'Cases by type, status and priority'],

                'stats' => [
                    ['key' => 'total',   'label_ar' => 'إجمالي القضايا', 'label_en' => 'Total',   'value' => $total,   'format' => 'number', 'color' => 'navy',    'icon' => 'shield'],
                    ['key' => 'open',    'label_ar' => 'مفتوحة',          'label_en' => 'Open',    'value' => $open,    'format' => 'number', 'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'pending', 'label_ar' => 'معلقة',           'label_en' => 'Pending', 'value' => $pending, 'format' => 'number', 'color' => 'amber',   'icon' => 'clock'],
                    ['key' => 'closed',  'label_ar' => 'مغلقة',           'label_en' => 'Closed',  'value' => $closed,  'format' => 'number', 'color' => 'red',     'icon' => 'cancel'],
                ],

                'monthly' => [
                    'title_ar' => 'القضايا المسجلة شهرياً',
                    'title_en' => 'Cases Filed Monthly',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'count', 'label_ar' => 'قضايا جديدة', 'label_en' => 'New Cases', 'color' => '#021B4A', 'values' => $this->padMonthly($monthlyRows)],
                    ],
                ],

                'breakdowns' => $breakdowns,

                'table' => $recentCases->count() > 0 ? [
                    'title_ar' => 'أحدث القضايا',
                    'title_en' => 'Recent Cases',
                    'columns' => [
                        ['key' => 'case_number', 'label_ar' => 'رقم القضية', 'label_en' => 'Case #', 'format' => 'text'],
                        ['key' => 'case_type',   'label_ar' => 'النوع',      'label_en' => 'Type',   'format' => 'text'],
                        ['key' => 'owner_name',  'label_ar' => 'المالك',     'label_en' => 'Owner',  'format' => 'text'],
                        ['key' => 'status',      'label_ar' => 'الحالة',     'label_en' => 'Status', 'format' => 'status'],
                        ['key' => 'filing_date', 'label_ar' => 'تاريخ الرفع','label_en' => 'Filed',  'format' => 'date'],
                    ],
                    'rows' => $recentCases->values(),
                ] : null,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 7. MEETINGS + VOTES
    // ─────────────────────────────────────────────────────────────────
    public function meetings(Request $request): JsonResponse
    {
        return $this->safeReport('meetings', function () use ($request) {
            $year = Carbon::now()->year;

            $total      = Meeting::count();
            $scheduled  = Meeting::where('status', 'scheduled')->count();
            $inProgress = Meeting::where('status', 'in_progress')->count();
            $completed  = Meeting::where('status', 'completed')->count();
            $cancelled  = Meeting::where('status', 'cancelled')->count();
            $totalVotes = Vote::count();

            // Schema fix: Meeting uses `scheduled_at` (not `meeting_date`)
            // and `type` (not `meeting_type`).
            $monthlyRows = Meeting::selectRaw('MONTH(COALESCE(scheduled_at, created_at)) AS m, COUNT(*) AS cnt')
                ->whereYear(DB::raw('COALESCE(scheduled_at, created_at)'), $year)
                ->groupByRaw('m')
                ->get()
                ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                ->all();

            $byType = Meeting::selectRaw('type, COUNT(*) AS cnt')
                ->whereNotNull('type')
                ->groupBy('type')
                ->orderByDesc('cnt')
                ->get();

            $upcoming = Meeting::whereIn('status', ['scheduled', 'in_progress'])
                ->whereNotNull('scheduled_at')
                ->orderBy('scheduled_at')
                ->take(15)
                ->get()
                ->map(fn ($m) => [
                    'meeting_number' => $m->meeting_number ?? '-',
                    'title'          => $m->title ?? '-',
                    'type'           => $m->type ?? '-',
                    'scheduled_at'   => optional($m->scheduled_at)->format('Y-m-d H:i'),
                    'status'         => $m->status,
                ]);

            $breakdowns = [
                [
                    'key' => 'meeting_status',
                    'title_ar' => 'الاجتماعات حسب الحالة',
                    'title_en' => 'Meetings by Status',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'مجدول',  'label_en' => 'Scheduled',   'value' => $scheduled,  'color' => '#3b82f6'],
                        ['label_ar' => 'جاري',   'label_en' => 'In Progress', 'value' => $inProgress, 'color' => '#f59e0b'],
                        ['label_ar' => 'مكتمل', 'label_en' => 'Completed',    'value' => $completed,  'color' => '#10b981'],
                        ['label_ar' => 'ملغي',  'label_en' => 'Cancelled',    'value' => $cancelled,  'color' => '#94a3b8'],
                    ],
                ],
            ];

            if ($byType->count() > 0) {
                $breakdowns[] = [
                    'key' => 'by_type',
                    'title_ar' => 'الاجتماعات حسب النوع',
                    'title_en' => 'Meetings by Type',
                    'type' => 'bar',
                    'items' => $byType->map(fn ($r, $i) => [
                        'label_ar' => $r->type ?: '-',
                        'label_en' => $r->type ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            return [
                'title'    => ['ar' => 'تقرير الاجتماعات والتصويتات', 'en' => 'Meetings & Votes Report'],
                'subtitle' => ['ar' => 'إحصائيات الاجتماعات والتصويتات', 'en' => 'Meeting and vote statistics'],

                'stats' => [
                    ['key' => 'total',       'label_ar' => 'إجمالي الاجتماعات', 'label_en' => 'Total',      'value' => $total,      'format' => 'number', 'color' => 'navy',    'icon' => 'calendar'],
                    ['key' => 'scheduled',   'label_ar' => 'مجدولة',             'label_en' => 'Scheduled',  'value' => $scheduled,  'format' => 'number', 'color' => 'blue',    'icon' => 'clock'],
                    ['key' => 'completed',   'label_ar' => 'مكتملة',             'label_en' => 'Completed',  'value' => $completed,  'format' => 'number', 'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'cancelled',   'label_ar' => 'ملغاة',              'label_en' => 'Cancelled',  'value' => $cancelled,  'format' => 'number', 'color' => 'red',     'icon' => 'cancel'],
                    ['key' => 'in_progress', 'label_ar' => 'جارية',              'label_en' => 'Running',    'value' => $inProgress, 'format' => 'number', 'color' => 'amber',   'icon' => 'wrench'],
                    ['key' => 'votes',       'label_ar' => 'إجمالي التصويتات',   'label_en' => 'Votes',      'value' => $totalVotes, 'format' => 'number', 'color' => 'indigo',  'icon' => 'vote'],
                ],

                'monthly' => [
                    'title_ar' => 'الاجتماعات المنعقدة شهرياً',
                    'title_en' => 'Meetings Held Monthly',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'count', 'label_ar' => 'عدد الاجتماعات', 'label_en' => 'Meeting Count', 'color' => '#021B4A', 'values' => $this->padMonthly($monthlyRows)],
                    ],
                ],

                'breakdowns' => $breakdowns,

                'table' => $upcoming->count() > 0 ? [
                    'title_ar' => 'الاجتماعات القادمة',
                    'title_en' => 'Upcoming Meetings',
                    'columns' => [
                        ['key' => 'meeting_number', 'label_ar' => 'رقم الاجتماع', 'label_en' => 'Meeting #',  'format' => 'text'],
                        ['key' => 'title',          'label_ar' => 'العنوان',      'label_en' => 'Title',      'format' => 'text'],
                        ['key' => 'type',           'label_ar' => 'النوع',        'label_en' => 'Type',       'format' => 'text'],
                        ['key' => 'scheduled_at',   'label_ar' => 'الموعد',       'label_en' => 'Scheduled',  'format' => 'date'],
                        ['key' => 'status',         'label_ar' => 'الحالة',       'label_en' => 'Status',     'format' => 'status'],
                    ],
                    'rows' => $upcoming->values(),
                ] : null,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 8. PARKING + VEHICLES
    // ─────────────────────────────────────────────────────────────────
    public function parking(Request $request): JsonResponse
    {
        return $this->safeReport('parking', function () use ($request) {
            $year = Carbon::now()->year;

            $totalSpots     = ParkingSpot::count();
            $available      = ParkingSpot::where('status', 'available')->count();
            $occupied       = ParkingSpot::where('status', 'occupied')->count();
            $totalVehicles  = Vehicle::count();
            $activeVehicles = Vehicle::where('status', 'active')->count();
            $occupancyPct   = $totalSpots > 0 ? round(($occupied / $totalSpots) * 100, 1) : 0;

            $spotsByType = ParkingSpot::selectRaw('parking_type, COUNT(*) AS cnt')
                ->whereNotNull('parking_type')
                ->groupBy('parking_type')
                ->orderByDesc('cnt')
                ->get();

            $vehiclesByType = Vehicle::selectRaw('car_type, COUNT(*) AS cnt')
                ->whereNotNull('car_type')
                ->groupBy('car_type')
                ->orderByDesc('cnt')
                ->get();

            $monthlyRows = Vehicle::selectRaw('MONTH(created_at) AS m, COUNT(*) AS cnt')
                ->whereYear('created_at', $year)
                ->groupByRaw('m')
                ->get()
                ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                ->all();

            $breakdowns = [
                [
                    'key' => 'spot_status',
                    'title_ar' => 'المواقف حسب الحالة',
                    'title_en' => 'Spots by Status',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'متاح',  'label_en' => 'Available', 'value' => $available, 'color' => '#10b981'],
                        ['label_ar' => 'مشغول', 'label_en' => 'Occupied',  'value' => $occupied,  'color' => '#ef4444'],
                    ],
                ],
            ];

            if ($spotsByType->count() > 0) {
                $breakdowns[] = [
                    'key' => 'spots_by_type',
                    'title_ar' => 'المواقف حسب النوع',
                    'title_en' => 'Spots by Type',
                    'type' => 'bar',
                    'items' => $spotsByType->map(fn ($r, $i) => [
                        'label_ar' => $r->parking_type ?: '-',
                        'label_en' => $r->parking_type ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            if ($vehiclesByType->count() > 0) {
                $breakdowns[] = [
                    'key' => 'vehicles_by_type',
                    'title_ar' => 'المركبات حسب النوع',
                    'title_en' => 'Vehicles by Type',
                    'type' => 'doughnut',
                    'items' => $vehiclesByType->map(fn ($r, $i) => [
                        'label_ar' => $r->car_type ?: '-',
                        'label_en' => $r->car_type ?: '-',
                        'value'    => (int) $r->cnt,
                        'color'    => self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            return [
                'title'    => ['ar' => 'تقرير المواقف والمركبات', 'en' => 'Parking & Vehicles Report'],
                'subtitle' => ['ar' => 'إشغال المواقف وأنواع المركبات', 'en' => 'Spot occupancy and vehicle distribution'],

                'stats' => [
                    ['key' => 'total_spots',     'label_ar' => 'إجمالي المواقف',  'label_en' => 'Total Spots',     'value' => $totalSpots,     'format' => 'number',  'color' => 'navy',    'icon' => 'parking'],
                    ['key' => 'available',       'label_ar' => 'متاحة',           'label_en' => 'Available',       'value' => $available,      'format' => 'number',  'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'occupied',        'label_ar' => 'مشغولة',          'label_en' => 'Occupied',        'value' => $occupied,       'format' => 'number',  'color' => 'amber',   'icon' => 'parking'],
                    ['key' => 'occupancy',       'label_ar' => 'نسبة الإشغال',    'label_en' => 'Occupancy',       'value' => $occupancyPct,   'format' => 'percent', 'color' => 'indigo',  'icon' => 'percent'],
                    ['key' => 'total_vehicles',  'label_ar' => 'إجمالي المركبات', 'label_en' => 'Total Vehicles',  'value' => $totalVehicles,  'format' => 'number',  'color' => 'blue',    'icon' => 'car'],
                    ['key' => 'active_vehicles', 'label_ar' => 'مركبات نشطة',     'label_en' => 'Active Vehicles', 'value' => $activeVehicles, 'format' => 'number',  'color' => 'emerald', 'icon' => 'car'],
                ],

                'monthly' => [
                    'title_ar' => 'تسجيل المركبات شهرياً',
                    'title_en' => 'Vehicles Registered Monthly',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'count', 'label_ar' => 'مركبات جديدة', 'label_en' => 'New Vehicles', 'color' => '#021B4A', 'values' => $this->padMonthly($monthlyRows)],
                    ],
                ],

                'breakdowns' => $breakdowns,

                'table' => null,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 9. USERS  (NEW)
    // ─────────────────────────────────────────────────────────────────
    public function users(Request $request): JsonResponse
    {
        return $this->safeReport('users', function () use ($request) {
            $year = Carbon::now()->year;

            $total       = User::count();
            $active      = User::where('is_active', true)->count();
            $inactive    = User::where('is_active', false)->count();
            $thisMonth   = User::whereMonth('created_at', Carbon::now()->month)
                                ->whereYear('created_at', $year)->count();

            $loggedIn30  = User::whereNotNull('last_login_at')
                                ->where('last_login_at', '>=', Carbon::now()->subDays(30))
                                ->count();

            $never       = User::whereNull('last_login_at')->count();

            // Joinless role aggregation — Role table is small.
            $byRole = DB::table('users')
                ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                ->selectRaw("COALESCE(roles.name_ar, 'بدون دور') AS role_ar, COALESCE(roles.name_en, 'No Role') AS role_en, roles.color AS role_color, COUNT(users.id) AS cnt")
                ->groupBy('roles.name_ar', 'roles.name_en', 'roles.color')
                ->orderByDesc('cnt')
                ->get();

            $monthlyRows = User::selectRaw('MONTH(created_at) AS m, COUNT(*) AS cnt')
                ->whereYear('created_at', $year)
                ->groupByRaw('m')
                ->get()
                ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                ->all();

            $loginsByMonth = [];
            try {
                if (Schema::hasColumn('users', 'last_login_at')) {
                    $loginsByMonth = User::selectRaw('MONTH(last_login_at) AS m, COUNT(*) AS cnt')
                        ->whereNotNull('last_login_at')
                        ->whereYear('last_login_at', $year)
                        ->groupByRaw('m')
                        ->get()
                        ->mapWithKeys(fn ($r) => [(int) $r->m => (int) $r->cnt])
                        ->all();
                }
            } catch (Throwable) { /* ignore */ }

            $recentLogins = User::with('userRole')
                ->whereNotNull('last_login_at')
                ->orderByDesc('last_login_at')
                ->take(15)
                ->get()
                ->map(fn ($u) => [
                    'name'          => $u->name,
                    'role'          => optional($u->userRole)->name_ar ?? '-',
                    'last_login_at' => optional($u->last_login_at)->format('Y-m-d H:i'),
                    'is_active'     => $u->is_active ? 'active' : 'inactive',
                ]);

            $breakdowns = [
                [
                    'key' => 'user_status',
                    'title_ar' => 'المستخدمون حسب الحالة',
                    'title_en' => 'Users by Status',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'نشط',     'label_en' => 'Active',   'value' => $active,   'color' => '#10b981'],
                        ['label_ar' => 'غير نشط', 'label_en' => 'Inactive', 'value' => $inactive, 'color' => '#94a3b8'],
                    ],
                ],
                [
                    'key' => 'login_recency',
                    'title_ar' => 'نشاط الدخول (آخر 30 يوم)',
                    'title_en' => 'Login Activity (last 30 days)',
                    'type' => 'doughnut',
                    'items' => [
                        ['label_ar' => 'دخلوا حديثاً', 'label_en' => 'Logged In',   'value' => $loggedIn30,                                  'color' => '#10b981'],
                        ['label_ar' => 'لم يدخلوا',   'label_en' => 'Inactive',    'value' => max(0, $total - $loggedIn30 - $never),       'color' => '#f59e0b'],
                        ['label_ar' => 'لم يسجلوا',   'label_en' => 'Never',       'value' => $never,                                       'color' => '#94a3b8'],
                    ],
                ],
            ];

            if ($byRole->count() > 0) {
                $breakdowns[] = [
                    'key' => 'by_role',
                    'title_ar' => 'المستخدمون حسب الدور',
                    'title_en' => 'Users by Role',
                    'type' => 'bar',
                    'items' => $byRole->map(fn ($r, $i) => [
                        'label_ar' => $r->role_ar ?? '-',
                        'label_en' => $r->role_en ?? '-',
                        'value'    => (int) $r->cnt,
                        'color'    => $r->role_color ?: self::PALETTE[$i % count(self::PALETTE)],
                    ])->values(),
                ];
            }

            return [
                'title'    => ['ar' => 'تقرير المستخدمين', 'en' => 'Users Report'],
                'subtitle' => ['ar' => 'المستخدمون والصلاحيات ونشاط الدخول', 'en' => 'Users, roles and login activity'],

                'stats' => [
                    ['key' => 'total',          'label_ar' => 'إجمالي المستخدمين',     'label_en' => 'Total Users',      'value' => $total,      'format' => 'number', 'color' => 'navy',    'icon' => 'users'],
                    ['key' => 'active',         'label_ar' => 'نشط',                    'label_en' => 'Active',           'value' => $active,     'format' => 'number', 'color' => 'emerald', 'icon' => 'check'],
                    ['key' => 'inactive',       'label_ar' => 'موقوف',                  'label_en' => 'Inactive',         'value' => $inactive,   'format' => 'number', 'color' => 'red',     'icon' => 'cancel'],
                    ['key' => 'logged_30',      'label_ar' => 'دخلوا (30 يوم)',         'label_en' => 'Logged In (30d)',  'value' => $loggedIn30, 'format' => 'number', 'color' => 'blue',    'icon' => 'check'],
                    ['key' => 'never_logged',   'label_ar' => 'لم يسجلوا الدخول أبداً', 'label_en' => 'Never Logged In',  'value' => $never,      'format' => 'number', 'color' => 'amber',   'icon' => 'clock'],
                    ['key' => 'new_this_month', 'label_ar' => 'جدد هذا الشهر',         'label_en' => 'New This Month',   'value' => $thisMonth,  'format' => 'number', 'color' => 'indigo',  'icon' => 'plus'],
                ],

                'monthly' => [
                    'title_ar' => 'النشاط الشهري للمستخدمين',
                    'title_en' => 'Monthly User Activity',
                    'year'     => $year,
                    'months'   => $this->monthsArray(),
                    'datasets' => [
                        ['key' => 'created', 'label_ar' => 'مستخدمون جدد',  'label_en' => 'New Users',  'color' => '#021B4A', 'values' => $this->padMonthly($monthlyRows)],
                        ['key' => 'logins',  'label_ar' => 'تسجيلات دخول',  'label_en' => 'Logins',     'color' => '#10b981', 'values' => $this->padMonthly($loginsByMonth)],
                    ],
                ],

                'breakdowns' => $breakdowns,

                'table' => $recentLogins->count() > 0 ? [
                    'title_ar' => 'أحدث تسجيلات الدخول',
                    'title_en' => 'Recent Logins',
                    'columns' => [
                        ['key' => 'name',          'label_ar' => 'الاسم',       'label_en' => 'Name',       'format' => 'text'],
                        ['key' => 'role',          'label_ar' => 'الدور',       'label_en' => 'Role',       'format' => 'text'],
                        ['key' => 'last_login_at', 'label_ar' => 'آخر دخول',    'label_en' => 'Last Login', 'format' => 'date'],
                        ['key' => 'is_active',     'label_ar' => 'الحالة',      'label_en' => 'Status',     'format' => 'status'],
                    ],
                    'rows' => $recentLogins->values(),
                ] : null,
            ];
        });
    }
}
