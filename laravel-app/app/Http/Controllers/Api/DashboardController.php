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

        $units = [
            'total'             => Unit::count(),
            'occupied'          => Unit::where('status', 'occupied')->count(),
            'vacant'            => Unit::where('status', 'vacant')->count(),
            'under_maintenance' => Unit::where('status', 'under_maintenance')->count(),
        ];

        $invoices = [
            'total'   => Invoice::count(),
            'paid'    => Invoice::where('status', 'paid')->count(),
            'pending' => Invoice::where('status', 'pending')->count(),
            'overdue' => Invoice::where('status', 'overdue')->count(),
        ];

        $maintenance = [
            'total'       => MaintenanceRequest::count(),
            'completed'   => MaintenanceRequest::where('status', 'completed')->count(),
            'in_progress' => MaintenanceRequest::where('status', 'in_progress')->count(),
            'pending'     => MaintenanceRequest::where('status', 'pending')->count(),
            'overdue'     => MaintenanceRequest::where('status', 'overdue')->count(),
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
}
