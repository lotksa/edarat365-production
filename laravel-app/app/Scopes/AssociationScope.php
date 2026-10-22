<?php

namespace App\Scopes;

use App\Services\AssociationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Facades\DB;

/**
 * One scope for lists, finds, eager relations, counts, exports and bulk writes.
 * SQL subqueries deliberately use the query builder, avoiding recursive model
 * scopes (owner -> unit -> owner). Explicit association IDs take precedence;
 * legacy records are resolved through their property/unit/parent, never guessed.
 */
final class AssociationScope implements Scope
{
    public const MODELS = [
        'Association', 'Property', 'Unit', 'Owner', 'Tenant', 'AssociationManager',
        'PropertyManager', 'Invoice', 'Contract', 'Meeting', 'MaintenanceRequest',
        'LegalCase', 'Facility', 'Booking', 'Vote', 'VotePhase', 'VoteResponse',
        'ParkingSpot', 'Vehicle', 'Transaction', 'Voucher', 'ApprovalRequest',
        'Resolution', 'PropertyDocument', 'PropertyComponent', 'UtilityMeter',
        'UnitComponent', 'UnitPrivatePart', 'UnitImage', 'CaseUpdate',
        'CasePermission', 'CaseMessage', 'CaseReminder', 'LegalRepresentative',
        'ActivityLog', 'Notification',
    ];

    public const DIRECT = [
        'properties', 'owners', 'tenants', 'association_managers', 'property_managers',
        'invoices', 'contracts', 'meetings', 'maintenance_requests', 'legal_cases',
        'facilities', 'bookings', 'votes', 'parking_spots', 'vehicles', 'transactions',
        'vouchers', 'approval_requests', 'legal_representatives', 'activity_logs', 'notifications',
    ];

    public const PEOPLE = ['owners', 'tenants', 'association_managers', 'property_managers', 'legal_representatives'];

    private const PARENTS = [
        'units' => ['property_id' => 'properties'],
        'invoices' => ['property_id' => 'properties', 'unit_id' => 'units'],
        'contracts' => ['property_id' => 'properties', 'unit_id' => 'units'],
        'meetings' => ['property_id' => 'properties'],
        'maintenance_requests' => ['property_id' => 'properties', 'unit_id' => 'units'],
        'legal_cases' => ['property_id' => 'properties', 'unit_id' => 'units'],
        'facilities' => ['property_id' => 'properties'],
        'bookings' => ['facility_id' => 'facilities'],
        'votes' => ['meeting_id' => 'meetings'],
        'parking_spots' => ['property_id' => 'properties'],
        'vehicles' => ['property_id' => 'properties', 'parking_spot_id' => 'parking_spots'],
        'transactions' => ['property_id' => 'properties', 'unit_id' => 'units', 'invoice_id' => 'invoices'],
        'approval_requests' => ['property_id' => 'properties'],
        'resolutions' => ['meeting_id' => 'meetings'],
        'property_documents' => ['property_id' => 'properties'],
        'property_components' => ['property_id' => 'properties'],
        'utility_meters' => ['property_id' => 'properties'],
        'unit_components' => ['unit_id' => 'units'],
        'unit_private_parts' => ['unit_id' => 'units'],
        'unit_images' => ['unit_id' => 'units'],
        'case_updates' => ['legal_case_id' => 'legal_cases'],
        'case_permissions' => ['legal_case_id' => 'legal_cases'],
        'case_messages' => ['legal_case_id' => 'legal_cases'],
        'case_reminders' => ['legal_case_id' => 'legal_cases'],
        'vote_phases' => ['vote_id' => 'votes'],
        'vote_responses' => ['vote_id' => 'votes'],
    ];

    public function apply(Builder $builder, Model $model): void
    {
        $id = app(AssociationContext::class)->id;
        if ($id !== null) {
            $this->constrain($builder->getQuery(), $model->getTable(), $id);
        }
    }

    public function ids(string $table, int $id): Query
    {
        $query = DB::table($table)->select($table.'.id');
        $this->constrain($query, $table, $id);

        return $query;
    }

    public function constrain(Query $query, string $table, int $id): void
    {
        if ($table === 'associations') {
            $query->where($table.'.id', $id);

            return;
        }
        $query->where(function (Query $scope) use ($table, $id) {
            $scope->whereRaw('1 = 0');
            if (in_array($table, self::DIRECT, true)) {
                $scope->orWhere($table.'.association_id', $id);
            }
            $scope->orWhere(function (Query $legacy) use ($table, $id) {
                if (in_array($table, self::DIRECT, true) && ! in_array($table, self::PEOPLE, true)) {
                    $legacy->whereNull($table.'.association_id');
                }
                foreach (self::PARENTS[$table] ?? [] as $key => $parent) {
                    $legacy->where(fn (Query $link) => $link->whereNull($table.'.'.$key)
                        ->orWhereIn($table.'.'.$key, $this->ids($parent, $id)));
                }
                $legacy->where(function (Query $links) use ($table, $id) {
                    $links->whereRaw('1 = 0');
                    foreach (self::PARENTS[$table] ?? [] as $key => $parent) {
                        $links->orWhereIn($table.'.'.$key, $this->ids($parent, $id));
                    }
                    $this->people($links, $table, $id);
                    if (in_array($table, ['activity_logs', 'notifications'], true)) {
                        $this->subjects($links, $table, $id);
                    }
                });
            });
        });
    }

    private function people(Query $links, string $table, int $id): void
    {
        if ($table === 'owners') {
            $links->orWhereIn('owners.id', DB::table('property_owners')->select('owner_id')
                ->whereIn('property_id', $this->ids('properties', $id)));
            $links->orWhereIn('owners.id', DB::table('unit_owners')->select('owner_id')
                ->whereIn('unit_id', $this->ids('units', $id)));
            foreach (['invoices', 'contracts', 'legal_cases', 'maintenance_requests', 'transactions', 'vehicles', 'bookings'] as $child) {
                $links->orWhereIn('owners.id', DB::table($child)->select('owner_id')
                    ->whereIn($child.'.id', $this->ids($child, $id)));
            }
        } elseif ($table === 'association_managers') {
            $links->orWhereIn($table.'.id', DB::table('associations')->select('manager_id')->where('id', $id));
        } elseif ($table === 'property_managers') {
            $links->orWhereIn($table.'.id', DB::table('properties')->select('property_manager_id')->where('association_id', $id));
        } elseif ($table === 'tenants') {
            $links->orWhereIn($table.'.id', DB::table('contracts')->select('tenant_id')
                ->whereIn('contracts.id', $this->ids('contracts', $id)));
        } elseif ($table === 'legal_representatives') {
            $links->orWhereIn($table.'.id', DB::table('case_permissions')->select('representative_id')
                ->whereIn('legal_case_id', $this->ids('legal_cases', $id)));
        } elseif ($table === 'vouchers') {
            // A legacy voucher has only owner_id. Resolve it ONLY if all known
            // property/unit memberships identify this one association.
            $members = DB::table('property_owners as po')->join('properties as p', 'p.id', '=', 'po.property_id')
                ->select('po.owner_id')->where('p.association_id', $id)
                ->union(DB::table('unit_owners as uo')->join('units as u', 'u.id', '=', 'uo.unit_id')
                    ->join('properties as p', 'p.id', '=', 'u.property_id')->select('uo.owner_id')->where('p.association_id', $id));
            $links->orWhere(function (Query $voucher) use ($id, $members) {
                $voucher->whereIn('vouchers.owner_id', $members)
                    ->whereNotIn('vouchers.owner_id', DB::table('property_owners as po')
                        ->join('properties as p', 'p.id', '=', 'po.property_id')->select('po.owner_id')
                        ->where(fn ($q) => $q->where('p.association_id', '!=', $id)->orWhereNull('p.association_id')))
                    ->whereNotIn('vouchers.owner_id', DB::table('unit_owners as uo')->join('units as u', 'u.id', '=', 'uo.unit_id')
                        ->join('properties as p', 'p.id', '=', 'u.property_id')->select('uo.owner_id')
                        ->where(fn ($q) => $q->where('p.association_id', '!=', $id)->orWhereNull('p.association_id')));
            });
        }
        // Origin does not prevent a genuinely shared identity being visible via
        // its existing relationships. See constrain() for the special handling.
    }

    private function subjects(Query $links, string $table, int $id): void
    {
        foreach (['association' => 'associations', 'property' => 'properties', 'unit' => 'units',
            'owner' => 'owners', 'invoice' => 'invoices', 'contract' => 'contracts',
            'meeting' => 'meetings', 'vote' => 'votes', 'maintenance' => 'maintenance_requests',
            'legal_case' => 'legal_cases', 'facility' => 'facilities', 'booking' => 'bookings',
            'voucher' => 'vouchers', 'vehicle' => 'vehicles', 'transaction' => 'transactions'] as $type => $target) {
            $links->orWhere(function (Query $subject) use ($table, $id, $type, $target) {
                $class = match ($type) {
                    'maintenance' => 'MaintenanceRequest', 'legal_case' => 'LegalCase',
                    default => ucfirst($type),
                };
                $subject->whereIn($table.'.subject_type', [$type, $target, str_replace('_', '-', $target), 'App\\Models\\'.$class])
                    ->whereIn($table.'.subject_id', $this->ids($target, $id));
            });
        }
    }
}
