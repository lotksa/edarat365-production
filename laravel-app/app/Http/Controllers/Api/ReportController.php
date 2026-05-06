<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Invoice, Voucher, Owner, Property, Unit, Contract, LegalCase, ParkingSpot, Vehicle, Meeting, Vote};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function financial(Request $request): JsonResponse
    {
        $invoices = Invoice::query();
        $vouchers = Voucher::query();

        $totalInvoices     = (clone $invoices)->count();
        $paidInvoices      = (clone $invoices)->where('status', 'paid')->count();
        $unpaidInvoices    = (clone $invoices)->where('status', 'unpaid')->count();
        $totalRevenue      = (clone $invoices)->where('status', 'paid')->sum('total_amount');
        $totalPending      = (clone $invoices)->where('status', 'unpaid')->sum('total_amount');
        $totalTax          = (clone $invoices)->sum('tax_amount');
        $totalDiscount     = (clone $invoices)->sum('discount_amount');

        $totalReceipts     = Voucher::where('type', 'receipt')->sum('amount');
        $totalPayments     = Voucher::where('type', 'payment')->sum('amount');
        $receiptCount      = Voucher::where('type', 'receipt')->count();
        $paymentCount      = Voucher::where('type', 'payment')->count();

        $monthly = Invoice::selectRaw("
            MONTH(issue_date) as month,
            SUM(CASE WHEN status='paid' THEN total_amount ELSE 0 END) as paid,
            SUM(CASE WHEN status='unpaid' THEN total_amount ELSE 0 END) as unpaid,
            COUNT(*) as count
        ")->whereYear('issue_date', Carbon::now()->year)
          ->groupByRaw('MONTH(issue_date)')
          ->orderBy('month')
          ->get();

        $statusBreakdown = [
            ['label' => 'مدفوع', 'label_en' => 'Paid', 'value' => $paidInvoices],
            ['label' => 'غير مدفوع', 'label_en' => 'Unpaid', 'value' => $unpaidInvoices],
        ];

        $recentInvoices = Invoice::with('owner')->latest()->take(10)->get()->map(fn ($i) => [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'owner_name' => $i->owner?->full_name ?? '-',
            'total_amount' => $i->total_amount,
            'status' => $i->status,
            'issue_date' => $i->issue_date?->format('Y-m-d'),
        ]);

        return response()->json([
            'stats' => [
                ['key' => 'total_invoices', 'label' => 'إجمالي الفواتير', 'label_en' => 'Total Invoices', 'value' => $totalInvoices, 'icon' => 'file'],
                ['key' => 'paid_invoices', 'label' => 'فواتير مدفوعة', 'label_en' => 'Paid', 'value' => $paidInvoices, 'icon' => 'check', 'color' => 'emerald'],
                ['key' => 'unpaid_invoices', 'label' => 'فواتير غير مدفوعة', 'label_en' => 'Unpaid', 'value' => $unpaidInvoices, 'icon' => 'clock', 'color' => 'amber'],
                ['key' => 'total_revenue', 'label' => 'إجمالي الإيرادات', 'label_en' => 'Revenue', 'value' => number_format($totalRevenue, 2), 'icon' => 'dollar', 'color' => 'blue', 'suffix' => 'ر.س'],
                ['key' => 'total_pending', 'label' => 'مبالغ معلقة', 'label_en' => 'Pending', 'value' => number_format($totalPending, 2), 'icon' => 'alert', 'color' => 'red', 'suffix' => 'ر.س'],
                ['key' => 'total_tax', 'label' => 'إجمالي الضريبة', 'label_en' => 'Tax', 'value' => number_format($totalTax, 2), 'icon' => 'percent', 'suffix' => 'ر.س'],
                ['key' => 'receipts', 'label' => 'سندات القبض', 'label_en' => 'Receipts', 'value' => $receiptCount, 'extra' => number_format($totalReceipts, 2) . ' ر.س', 'color' => 'emerald'],
                ['key' => 'payments', 'label' => 'سندات الصرف', 'label_en' => 'Payments', 'value' => $paymentCount, 'extra' => number_format($totalPayments, 2) . ' ر.س', 'color' => 'red'],
            ],
            'monthly' => $monthly,
            'status_breakdown' => $statusBreakdown,
            'recent_invoices' => $recentInvoices,
        ]);
    }

    public function owners(Request $request): JsonResponse
    {
        $total       = Owner::count();
        $active      = Owner::where('status', 'active')->count();
        $inactive    = Owner::where('status', 'inactive')->count();

        $thisMonth   = Owner::whereMonth('created_at', Carbon::now()->month)
                            ->whereYear('created_at', Carbon::now()->year)->count();

        $withUnits   = Owner::whereHas('units')->count();
        $withoutUnits = $total - $withUnits;

        $monthly = Owner::selectRaw("MONTH(created_at) as month, COUNT(*) as count")
            ->whereYear('created_at', Carbon::now()->year)
            ->groupByRaw('MONTH(created_at)')
            ->orderBy('month')
            ->get();

        $byProperty = DB::table('owners')
            ->join('owner_unit', 'owners.id', '=', 'owner_unit.owner_id')
            ->join('units', 'owner_unit.unit_id', '=', 'units.id')
            ->join('properties', 'units.property_id', '=', 'properties.id')
            ->select('properties.name as property_name', DB::raw('COUNT(DISTINCT owners.id) as count'))
            ->groupBy('properties.name')
            ->orderByDesc('count')
            ->take(10)
            ->get();

        $recentOwners = Owner::latest()->take(10)->get()->map(fn ($o) => [
            'id' => $o->id,
            'full_name' => $o->full_name,
            'email' => $o->email,
            'phone' => $o->phone,
            'status' => $o->status,
            'created_at' => $o->created_at?->format('Y-m-d'),
        ]);

        return response()->json([
            'stats' => [
                ['key' => 'total', 'label' => 'إجمالي الملاك', 'label_en' => 'Total Owners', 'value' => $total, 'icon' => 'users'],
                ['key' => 'active', 'label' => 'نشط', 'label_en' => 'Active', 'value' => $active, 'color' => 'emerald'],
                ['key' => 'inactive', 'label' => 'غير نشط', 'label_en' => 'Inactive', 'value' => $inactive, 'color' => 'red'],
                ['key' => 'new_this_month', 'label' => 'جدد هذا الشهر', 'label_en' => 'New This Month', 'value' => $thisMonth, 'color' => 'blue'],
                ['key' => 'with_units', 'label' => 'يملكون وحدات', 'label_en' => 'With Units', 'value' => $withUnits, 'color' => 'indigo'],
                ['key' => 'without_units', 'label' => 'بدون وحدات', 'label_en' => 'No Units', 'value' => $withoutUnits, 'color' => 'amber'],
            ],
            'monthly' => $monthly,
            'by_property' => $byProperty,
            'recent_owners' => $recentOwners,
        ]);
    }

    public function properties(Request $request): JsonResponse
    {
        $totalProperties = Property::count();
        $activeProperties = Property::where('status', 'active')->count();
        $totalUnits = Unit::count();
        $occupiedUnits = Unit::where('status', 'occupied')->count();
        $vacantUnits = Unit::where('status', 'vacant')->orWhere('status', 'active')->count();
        $draftUnits = Unit::where('status', 'draft')->count();

        $occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

        $unitsByType = Unit::selectRaw("type, COUNT(*) as count")
            ->groupBy('type')->get();

        $propertiesByType = Property::selectRaw("property_type as type, COUNT(*) as count")
            ->groupBy('property_type')->get();

        $unitsByProperty = Unit::join('properties', 'units.property_id', '=', 'properties.id')
            ->selectRaw("properties.name as property_name, COUNT(units.id) as total, SUM(CASE WHEN units.status='occupied' THEN 1 ELSE 0 END) as occupied")
            ->groupBy('properties.name')
            ->orderByDesc('total')
            ->take(10)
            ->get();

        return response()->json([
            'stats' => [
                ['key' => 'total_properties', 'label' => 'إجمالي العقارات', 'label_en' => 'Properties', 'value' => $totalProperties, 'icon' => 'building'],
                ['key' => 'active_properties', 'label' => 'عقارات نشطة', 'label_en' => 'Active', 'value' => $activeProperties, 'color' => 'emerald'],
                ['key' => 'total_units', 'label' => 'إجمالي الوحدات', 'label_en' => 'Total Units', 'value' => $totalUnits, 'icon' => 'grid'],
                ['key' => 'occupied', 'label' => 'مشغولة', 'label_en' => 'Occupied', 'value' => $occupiedUnits, 'color' => 'blue'],
                ['key' => 'vacant', 'label' => 'شاغرة', 'label_en' => 'Vacant', 'value' => $vacantUnits, 'color' => 'amber'],
                ['key' => 'occupancy_rate', 'label' => 'نسبة الإشغال', 'label_en' => 'Occupancy', 'value' => $occupancyRate, 'suffix' => '%', 'color' => 'indigo'],
            ],
            'units_by_type' => $unitsByType,
            'properties_by_type' => $propertiesByType,
            'units_by_property' => $unitsByProperty,
        ]);
    }

    public function contracts(Request $request): JsonResponse
    {
        $total = Contract::count();
        $active = Contract::where('status', 'active')->count();
        $expired = Contract::where('status', 'expired')->count();
        $terminated = Contract::where('status', 'terminated')->count();
        $draft = Contract::where('status', 'draft')->count();

        $expiringIn30 = Contract::where('status', 'active')
            ->whereBetween('end_date', [Carbon::now(), Carbon::now()->addDays(30)])->count();

        $monthly = Contract::selectRaw("MONTH(start_date) as month, COUNT(*) as count")
            ->whereYear('start_date', Carbon::now()->year)
            ->groupByRaw('MONTH(start_date)')->orderBy('month')->get();

        $byNature = Contract::selectRaw("contract_nature as nature, COUNT(*) as count")
            ->groupBy('contract_nature')->get();

        $byStatus = [
            ['label' => 'نشط', 'label_en' => 'Active', 'value' => $active],
            ['label' => 'منتهي', 'label_en' => 'Expired', 'value' => $expired],
            ['label' => 'ملغي', 'label_en' => 'Terminated', 'value' => $terminated],
            ['label' => 'مسودة', 'label_en' => 'Draft', 'value' => $draft],
        ];

        $expiringSoon = Contract::with('owner', 'property')
            ->where('status', 'active')
            ->where('end_date', '>=', Carbon::now())
            ->orderBy('end_date')
            ->take(10)->get()->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'owner_name' => $c->owner?->full_name ?? '-',
                'property_name' => $c->property?->name ?? '-',
                'end_date' => $c->end_date?->format('Y-m-d'),
                'days_left' => Carbon::now()->diffInDays($c->end_date, false),
            ]);

        return response()->json([
            'stats' => [
                ['key' => 'total', 'label' => 'إجمالي العقود', 'label_en' => 'Total', 'value' => $total, 'icon' => 'file'],
                ['key' => 'active', 'label' => 'نشط', 'label_en' => 'Active', 'value' => $active, 'color' => 'emerald'],
                ['key' => 'expired', 'label' => 'منتهي', 'label_en' => 'Expired', 'value' => $expired, 'color' => 'red'],
                ['key' => 'expiring_30', 'label' => 'ينتهي خلال 30 يوم', 'label_en' => 'Expiring Soon', 'value' => $expiringIn30, 'color' => 'amber'],
                ['key' => 'terminated', 'label' => 'ملغي', 'label_en' => 'Terminated', 'value' => $terminated, 'color' => 'slate'],
                ['key' => 'draft', 'label' => 'مسودة', 'label_en' => 'Draft', 'value' => $draft, 'color' => 'blue'],
            ],
            'monthly' => $monthly,
            'by_nature' => $byNature,
            'by_status' => $byStatus,
            'expiring_soon' => $expiringSoon,
        ]);
    }

    public function maintenance(Request $request): JsonResponse
    {
        $total     = DB::table('maintenance_requests')->count();
        $pending   = DB::table('maintenance_requests')->where('status', 'pending')->count();
        $progress  = DB::table('maintenance_requests')->where('status', 'in_progress')->count();
        $completed = DB::table('maintenance_requests')->where('status', 'completed')->count();
        $cancelled = DB::table('maintenance_requests')->where('status', 'cancelled')->count();

        $monthly = DB::table('maintenance_requests')
            ->selectRaw("MONTH(created_at) as month, COUNT(*) as count")
            ->whereYear('created_at', Carbon::now()->year)
            ->groupByRaw('MONTH(created_at)')->orderBy('month')->get();

        $byStatus = [
            ['label' => 'معلق', 'label_en' => 'Pending', 'value' => $pending],
            ['label' => 'قيد التنفيذ', 'label_en' => 'In Progress', 'value' => $progress],
            ['label' => 'مكتمل', 'label_en' => 'Completed', 'value' => $completed],
            ['label' => 'ملغي', 'label_en' => 'Cancelled', 'value' => $cancelled],
        ];

        return response()->json([
            'stats' => [
                ['key' => 'total', 'label' => 'إجمالي الطلبات', 'label_en' => 'Total', 'value' => $total, 'icon' => 'wrench'],
                ['key' => 'pending', 'label' => 'معلق', 'label_en' => 'Pending', 'value' => $pending, 'color' => 'amber'],
                ['key' => 'in_progress', 'label' => 'قيد التنفيذ', 'label_en' => 'In Progress', 'value' => $progress, 'color' => 'blue'],
                ['key' => 'completed', 'label' => 'مكتمل', 'label_en' => 'Completed', 'value' => $completed, 'color' => 'emerald'],
                ['key' => 'cancelled', 'label' => 'ملغي', 'label_en' => 'Cancelled', 'value' => $cancelled, 'color' => 'red'],
            ],
            'monthly' => $monthly,
            'by_status' => $byStatus,
        ]);
    }

    public function legal(Request $request): JsonResponse
    {
        $total  = LegalCase::count();
        $open   = LegalCase::where('status', 'open')->count();
        $closed = LegalCase::where('status', 'closed')->count();
        $pending = LegalCase::where('status', 'pending')->count();

        $monthly = LegalCase::selectRaw("MONTH(created_at) as month, COUNT(*) as count")
            ->whereYear('created_at', Carbon::now()->year)
            ->groupByRaw('MONTH(created_at)')->orderBy('month')->get();

        $byType = LegalCase::selectRaw("case_type as type, COUNT(*) as count")
            ->groupBy('case_type')->get();

        $byStatus = [
            ['label' => 'مفتوحة', 'label_en' => 'Open', 'value' => $open],
            ['label' => 'معلقة', 'label_en' => 'Pending', 'value' => $pending],
            ['label' => 'مغلقة', 'label_en' => 'Closed', 'value' => $closed],
        ];

        $recentCases = LegalCase::with('owner')->latest()->take(10)->get()->map(fn ($c) => [
            'id' => $c->id,
            'case_number' => $c->case_number,
            'case_type' => $c->case_type,
            'owner_name' => $c->owner?->full_name ?? '-',
            'status' => $c->status,
            'case_date' => $c->case_date?->format('Y-m-d') ?? $c->created_at?->format('Y-m-d'),
        ]);

        return response()->json([
            'stats' => [
                ['key' => 'total', 'label' => 'إجمالي القضايا', 'label_en' => 'Total', 'value' => $total, 'icon' => 'alert'],
                ['key' => 'open', 'label' => 'مفتوحة', 'label_en' => 'Open', 'value' => $open, 'color' => 'emerald'],
                ['key' => 'pending', 'label' => 'معلقة', 'label_en' => 'Pending', 'value' => $pending, 'color' => 'amber'],
                ['key' => 'closed', 'label' => 'مغلقة', 'label_en' => 'Closed', 'value' => $closed, 'color' => 'red'],
            ],
            'monthly' => $monthly,
            'by_type' => $byType,
            'by_status' => $byStatus,
            'recent_cases' => $recentCases,
        ]);
    }

    public function meetings(Request $request): JsonResponse
    {
        $total      = Meeting::count();
        $scheduled  = Meeting::where('status', 'scheduled')->count();
        $completed  = Meeting::where('status', 'completed')->count();
        $cancelled  = Meeting::where('status', 'cancelled')->count();
        $inProgress = Meeting::where('status', 'in_progress')->count();
        $totalVotes = Vote::count();

        $monthly = Meeting::selectRaw("MONTH(meeting_date) as month, COUNT(*) as count")
            ->whereYear('meeting_date', Carbon::now()->year)
            ->groupByRaw('MONTH(meeting_date)')->orderBy('month')->get();

        $byType = Meeting::selectRaw("meeting_type as type, COUNT(*) as count")
            ->groupBy('meeting_type')->get();

        $byStatus = [
            ['label' => 'مجدول', 'label_en' => 'Scheduled', 'value' => $scheduled],
            ['label' => 'جاري', 'label_en' => 'In Progress', 'value' => $inProgress],
            ['label' => 'مكتمل', 'label_en' => 'Completed', 'value' => $completed],
            ['label' => 'ملغي', 'label_en' => 'Cancelled', 'value' => $cancelled],
        ];

        return response()->json([
            'stats' => [
                ['key' => 'total', 'label' => 'إجمالي الاجتماعات', 'label_en' => 'Total', 'value' => $total, 'icon' => 'calendar'],
                ['key' => 'scheduled', 'label' => 'مجدول', 'label_en' => 'Scheduled', 'value' => $scheduled, 'color' => 'blue'],
                ['key' => 'completed', 'label' => 'مكتمل', 'label_en' => 'Completed', 'value' => $completed, 'color' => 'emerald'],
                ['key' => 'cancelled', 'label' => 'ملغي', 'label_en' => 'Cancelled', 'value' => $cancelled, 'color' => 'red'],
                ['key' => 'votes', 'label' => 'إجمالي التصويتات', 'label_en' => 'Votes', 'value' => $totalVotes, 'color' => 'indigo'],
            ],
            'monthly' => $monthly,
            'by_type' => $byType,
            'by_status' => $byStatus,
        ]);
    }

    public function parking(Request $request): JsonResponse
    {
        $totalSpots = ParkingSpot::count();
        $available  = ParkingSpot::where('status', 'available')->count();
        $occupied   = ParkingSpot::where('status', 'occupied')->count();

        $totalVehicles = Vehicle::count();
        $activeVehicles = Vehicle::where('status', 'active')->count();

        $spotsByType = ParkingSpot::selectRaw("parking_type as type, COUNT(*) as count")
            ->groupBy('parking_type')->get();

        $vehiclesByType = Vehicle::selectRaw("car_type as type, COUNT(*) as count")
            ->groupBy('car_type')->get();

        $byStatus = [
            ['label' => 'متاح', 'label_en' => 'Available', 'value' => $available],
            ['label' => 'مشغول', 'label_en' => 'Occupied', 'value' => $occupied],
        ];

        return response()->json([
            'stats' => [
                ['key' => 'total_spots', 'label' => 'إجمالي المواقف', 'label_en' => 'Total Spots', 'value' => $totalSpots, 'icon' => 'parking'],
                ['key' => 'available', 'label' => 'متاح', 'label_en' => 'Available', 'value' => $available, 'color' => 'emerald'],
                ['key' => 'occupied', 'label' => 'مشغول', 'label_en' => 'Occupied', 'value' => $occupied, 'color' => 'amber'],
                ['key' => 'total_vehicles', 'label' => 'إجمالي المركبات', 'label_en' => 'Vehicles', 'value' => $totalVehicles, 'icon' => 'car'],
                ['key' => 'active_vehicles', 'label' => 'مركبات نشطة', 'label_en' => 'Active', 'value' => $activeVehicles, 'color' => 'blue'],
            ],
            'spots_by_type' => $spotsByType,
            'vehicles_by_type' => $vehiclesByType,
            'spots_by_status' => $byStatus,
        ]);
    }
}
