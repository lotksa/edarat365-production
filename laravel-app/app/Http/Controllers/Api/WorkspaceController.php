<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Association;
use App\Models\AssociationManager;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\LegalRepresentative;
use App\Models\MaintenanceRequest;
use App\Models\Meeting;
use App\Models\Owner;
use App\Models\Property;
use App\Models\PropertyManager;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Voucher;
use App\Scopes\AssociationScope;
use App\Services\AssociationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\DashboardAnalytics;
use Illuminate\Validation\Rule;

final class WorkspaceController extends Controller
{
    public function overview(Request $request, DashboardAnalytics $analyticsService)
    {
        $period = $request->validate(['period' => ['sometimes', Rule::in(['all', '6', '12', '24'])]])['period'] ?? 'all';
        $context = app(AssociationContext::class);
        $rows = [];
        $attention = [];
        $activities = [];
        $nextMeeting = null;
        try {
            foreach (Association::with(['manager', 'city', 'district'])->orderBy('name')->get() as $association) {
                $context->id = $association->id;
                $row = $association->toArray();
                $row['properties_count'] = $request->user()->hasPermission('properties.view') ? $association->properties()->count() : null;
                $row['units_count'] = $request->user()->hasPermission('units.view') ? Unit::count() : null;
                $row['owners_count'] = $request->user()->hasPermission('owners.view') ? Owner::count() : null;
                $row['financial'] = $analyticsService->can($request->user(), 'invoices.view') ? $analyticsService->financial((string) $period, false) : null;
                $row['unpaid_count'] = $row['financial']['unpaid_count'] ?? null;
                $row['unpaid_amount'] = $row['financial']['outstanding'] ?? null;
                $rows[] = $row;
                if ($association->status === 'draft') {
                    $attention[] = ['association_id' => $association->id, 'association_name' => $association->name,
                        'kind' => 'setup', 'count' => null, 'path' => '/associations/'.$association->id];
                }
                if ($row['unpaid_count']) {
                    $attention[] = ['association_id' => $association->id, 'association_name' => $association->name,
                        'kind' => 'invoices', 'count' => $row['unpaid_count'], 'path' => '/invoices?status=unpaid'];
                }
                if ($request->user()->hasPermission('meetings.view')) {
                    $meeting = Meeting::where('scheduled_at', '>=', now())->whereNotIn('status', ['cancelled', 'completed'])
                        ->orderBy('scheduled_at')->first(['id', 'title', 'scheduled_at', 'association_id']);
                    if ($meeting && (! $nextMeeting || $meeting->scheduled_at < $nextMeeting['scheduled_at'])) {
                        $nextMeeting = $meeting->toArray() + ['association_name' => $association->name];
                    }
                }
                if ($request->user()->hasPermission('activity_log.view')) {
                    foreach (ActivityLog::latest('id')->limit(3)->get(['id', 'action', 'description', 'performer', 'created_at']) as $log) {
                        $activities[] = $log->toArray() + ['association_id' => $association->id, 'association_name' => $association->name];
                    }
                }
            }
        } finally {
            $context->id = null;
        }
        usort($activities, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
        $analytics = $analyticsService->snapshot($request->user(), (string) $period);
        if ($analytics['financial'] !== null) {
            $analytics['unassigned'] = $analyticsService->unassignedFinancial(array_column($rows, 'id'));
        }

        return response()->json(['data' => $rows, 'attention' => $attention,
            'next_meeting' => $nextMeeting, 'activities' => array_slice($activities, 0, 6), 'analytics' => $analytics]);
    }

    private const LEGACY = [
        'owners' => Owner::class, 'properties' => Property::class,
        'tenants' => Tenant::class, 'association-managers' => AssociationManager::class,
        'property-managers' => PropertyManager::class, 'vouchers' => Voucher::class,
        'invoices' => Invoice::class, 'contracts' => Contract::class,
        'maintenance' => MaintenanceRequest::class, 'legal-cases' => LegalCase::class,
        'meetings' => Meeting::class, 'legal-representatives' => LegalRepresentative::class,
    ];

    private function unassignedQuery(string $module)
    {
        abort_unless(isset(self::LEGACY[$module]), 404);
        $class = self::LEGACY[$module];
        $query = $class::query()->whereNull('association_id');
        $table = (new $class)->getTable();
        foreach (Association::pluck('id') as $id) {
            $query->whereNotIn($table.'.id', app(AssociationScope::class)->ids($table, $id));
        }

        return $query;
    }

    public function unassigned(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $module = $request->query('module', 'owners');
        $counts = [];
        foreach (array_keys(self::LEGACY) as $name) {
            $counts[$name] = $this->unassignedQuery($name)->count();
        }
        $query = $this->unassignedQuery($module);
        if ($module === 'invoices') {
            $query->with('owner:id,full_name,account_number');
        }
        $rows = $query->orderBy('id')->paginate(20);

        return response()->json(['data' => $rows->items(), 'counts' => $counts,
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function assign(Request $request, string $module, int $id)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['association_id' => ['required', 'integer', 'exists:associations,id']]);

        return DB::transaction(function () use ($module, $id, $data) {
            $record = $this->unassignedQuery($module)->lockForUpdate()->findOrFail($id);
            $record->association_id = $data['association_id'];
            $record->save();
            ActivityLog::record($module, $record->id, 'workspace_assigned', 'تم ربط سجل سابق بالجمعية', null, $data);

            return response()->json(['message' => 'تم ربط السجل مع الحفاظ على جميع بياناته']);
        });
    }
}
