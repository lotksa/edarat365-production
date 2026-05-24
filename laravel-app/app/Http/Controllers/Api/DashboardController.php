<?php

namespace App\Http\Controllers\Api;

use App\Models\Association;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\MaintenanceRequest;
use App\Models\Meeting;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $owners = [
            'total'    => Owner::count(),
            'active'   => Owner::where('status', 'active')->count(),
            'inactive' => Owner::where('status', 'inactive')->count(),
        ];

        $assocQuery = Association::query();
        $associations = [
            'total'    => $assocQuery->count(),
            'active'   => Association::where('status', 'active')->count(),
            'inactive' => Association::where('status', 'inactive')->count(),
            'total_properties' => Property::whereNotNull('association_id')->count(),
            'total_owners' => \DB::table('unit_owners')
                ->join('units', 'unit_owners.unit_id', '=', 'units.id')
                ->join('properties', 'units.property_id', '=', 'properties.id')
                ->whereNotNull('properties.association_id')
                ->distinct('unit_owners.owner_id')
                ->count('unit_owners.owner_id'),
        ];

        $properties = [
            'total'       => Property::count(),
            'active'      => Property::where('status', 'active')->count(),
            'residential' => Property::where('type', 'residential')->count(),
            'commercial'  => Property::where('type', 'commercial')->count(),
            'mixed'       => Property::where('type', 'mixed')->count(),
        ];

        $unitStatuses = $this->statusCounts(Unit::class);
        $unitMaintenance = $this->countStatuses($unitStatuses, ['maintenance', 'under_maintenance']);
        $units = [
            'total'             => Unit::count(),
            'active'            => $this->countStatuses($unitStatuses, 'active'),
            'occupied'          => $this->countStatuses($unitStatuses, 'occupied'),
            'vacant'            => $this->countStatuses($unitStatuses, 'vacant'),
            'maintenance'       => $unitMaintenance,
            'under_maintenance' => $unitMaintenance,
            'reserved'          => $this->countStatuses($unitStatuses, 'reserved'),
            'draft'             => $this->countStatuses($unitStatuses, 'draft'),
        ];

        $invoiceStatuses = Invoice::query()
            ->whereNull('cancelled_at')
            ->where('status', '!=', 'cancelled')
            ->select('status', \DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $invoices = [
            'total'     => Invoice::count(),
            'paid'      => $this->countStatuses($invoiceStatuses, 'paid'),
            'unpaid'    => $this->countStatuses($invoiceStatuses, 'unpaid'),
            'pending'   => $this->countStatuses($invoiceStatuses, 'pending'),
            'overdue'   => $this->countStatuses($invoiceStatuses, 'overdue'),
            'partial'   => $this->countStatuses($invoiceStatuses, 'partial'),
            'draft'     => $this->countStatuses($invoiceStatuses, 'draft'),
            'cancelled' => Invoice::where('status', 'cancelled')->orWhereNotNull('cancelled_at')->count(),
        ];

        $maintenanceStatuses = $this->statusCounts(MaintenanceRequest::class);
        $maintenance = [
            'total'       => MaintenanceRequest::count(),
            'open'        => $this->countStatuses($maintenanceStatuses, ['open', 'pending']),
            'in_progress' => $this->countStatuses($maintenanceStatuses, 'in_progress'),
            'on_hold'     => $this->countStatuses($maintenanceStatuses, 'on_hold'),
            'completed'   => $this->countStatuses($maintenanceStatuses, 'completed'),
            'closed'      => $this->countStatuses($maintenanceStatuses, 'closed'),
            'cancelled'   => $this->countStatuses($maintenanceStatuses, 'cancelled'),
            'pending'     => $this->countStatuses($maintenanceStatuses, 'pending'),
            'overdue'     => $this->countStatuses($maintenanceStatuses, 'overdue'),
        ];

        $legalCases = [
            'total'   => LegalCase::count(),
            'open'    => LegalCase::where('status', 'open')->count(),
            'closed'  => LegalCase::where('status', 'closed')->count(),
            'pending' => LegalCase::where('status', 'pending')->count(),
        ];

        $contracts = [
            'total'   => Contract::count(),
            'active'  => Contract::where('status', 'active')->count(),
            'expired' => Contract::where('status', 'expired')->count(),
        ];

        $meetings = [
            'total'    => Meeting::count(),
            'upcoming' => Meeting::where('scheduled_at', '>=', now())->count(),
        ];

        $bookings = [
            'upcoming' => Booking::where('starts_at', '>=', now())->count(),
        ];

        $recentMaintenance = MaintenanceRequest::query()
            ->select('id', 'title', 'priority', 'status', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentInvoices = Invoice::query()
            ->select('id', 'amount', 'due_date', 'status', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'owners'              => $owners,
                'associations'        => $associations,
                'properties'          => $properties,
                'units'               => $units,
                'invoices'            => $invoices,
                'maintenance'         => $maintenance,
                'legal_cases'         => $legalCases,
                'contracts'           => $contracts,
                'meetings'            => $meetings,
                'bookings'            => $bookings,
                'recent_maintenance'  => $recentMaintenance,
                'recent_invoices'     => $recentInvoices,
            ],
        ]);
    }

    private function statusCounts(string $modelClass): \Illuminate\Support\Collection
    {
        return $modelClass::query()
            ->select('status', \DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }

    private function countStatuses(\Illuminate\Support\Collection $counts, string|array $statuses): int
    {
        $statuses = (array) $statuses;

        return array_reduce($statuses, function (int $total, string $status) use ($counts): int {
            return $total + (int) ($counts[$status] ?? 0);
        }, 0);
    }
}
