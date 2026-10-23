<?php

namespace App\Services;

use App\Models\{Booking, Contract, Facility, Invoice, LegalCase, MaintenanceRequest, Meeting, Owner, ParkingSpot, Property, Transaction, Unit, User, Vehicle, Vote, Voucher};
use App\Scopes\AssociationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only, shared definitions for the portfolio and association dashboards. */
final class DashboardAnalytics
{
    public const MODULES = [
        'invoices' => [Invoice::class, 'invoices.view'],
        'maintenance' => [MaintenanceRequest::class, 'maintenance.view'],
        'contracts' => [Contract::class, 'contracts.view'],
        'legal_cases' => [LegalCase::class, 'legal_cases.view'],
        'facilities' => [Facility::class, 'facilities.view'],
        'bookings' => [Booking::class, 'facilities.view'],
        'meetings' => [Meeting::class, 'meetings.view'],
        'votes' => [Vote::class, 'votes.view'],
        'properties' => [Property::class, 'properties.view'],
        'units' => [Unit::class, 'units.view'],
        'owners' => [Owner::class, 'owners.view'],
        'parking_spots' => [ParkingSpot::class, 'vehicles.view'],
        'vehicles' => [Vehicle::class, 'vehicles.view'],
        'vouchers' => [Voucher::class, 'vouchers.view'],
        'transactions' => [Transaction::class, 'vehicles.view'],
    ];

    public function can(User $user, string $permission): bool
    {
        $user->loadMissing('userRole.permissions');

        return $user->isSuperAdmin() || $user->hasPermission($permission);
    }

    public function snapshot(User $user, string $period = 'all'): array
    {
        $modules = [];
        foreach (self::MODULES as $key => [$class, $permission]) {
            if (! $this->can($user, $permission)) {
                continue;
            }
            $column = $key === 'facilities' ? "CASE WHEN is_active = 1 THEN 'active' ELSE 'inactive' END"
                : ($key === 'invoices' ? "CASE WHEN cancelled_at IS NOT NULL THEN 'cancelled' ELSE COALESCE(status, 'unspecified') END" : "COALESCE(status, 'unspecified')");
            $statuses = $class::query()->selectRaw($column.' AS state, COUNT(*) AS aggregate')
                ->groupByRaw($column)->orderBy('state')->pluck('aggregate', 'state')
                ->map(fn ($value) => (int) $value)->all();
            $modules[$key] = ['total' => array_sum($statuses), 'statuses' => $statuses];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'association_id' => app(AssociationContext::class)->id,
            'modules' => $modules,
            'financial' => $this->can($user, 'invoices.view') ? $this->financial($period) : null,
            // Vouchers and transactions are separate ledgers, never added to
            // invoice settlements (which would count the same money twice).
            'cash' => $this->can($user, 'vouchers.view') ? $this->cash() : null,
        ];
    }

    private function issued(): Builder
    {
        return Invoice::whereNull('cancelled_at')->whereNotIn('status', ['cancelled', 'draft']);
    }

    public function financial(string $period = 'all', bool $withTrend = true): array
    {
        // A stored zero is valid. Only NULL legacy totals use the line totals.
        $amount = 'COALESCE(total_amount, amount + COALESCE(tax_amount, 0) - COALESCE(discount_amount, 0))';
        $today = today()->toDateString();
        $row = $this->issued()->selectRaw("COUNT(*) AS issued_count, COALESCE(SUM($amount), 0) AS issued,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN $amount ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
            COALESCE(SUM(CASE WHEN status IN ('unpaid', 'pending', 'overdue') THEN $amount ELSE 0 END), 0) AS outstanding,
            COALESCE(SUM(CASE WHEN status IN ('unpaid', 'pending', 'overdue') THEN 1 ELSE 0 END), 0) AS unpaid_count,
            COALESCE(SUM(CASE WHEN status IN ('unpaid', 'pending', 'overdue') AND due_date < ? THEN $amount ELSE 0 END), 0) AS overdue,
            COALESCE(SUM(CASE WHEN status IN ('unpaid', 'pending', 'overdue') AND due_date < ? THEN 1 ELSE 0 END), 0) AS overdue_count,
            COALESCE(SUM(CASE WHEN status = 'partial' THEN $amount ELSE 0 END), 0) AS partial_face_amount,
            COALESCE(SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END), 0) AS partial_count,
            COALESCE(SUM(CASE WHEN status = 'paid' AND payment_date IS NULL THEN 1 ELSE 0 END), 0) AS undated_paid_count,
            COALESCE(SUM(CASE WHEN status NOT IN ('paid', 'unpaid', 'pending', 'overdue', 'partial') THEN 1 ELSE 0 END), 0) AS unclassified_count", [$today, $today])->first();
        $result = [];
        foreach (['issued', 'paid', 'outstanding', 'overdue', 'partial_face_amount'] as $field) {
            $result[$field] = round((float) $row->$field, 2);
        }
        foreach (['issued_count', 'paid_count', 'unpaid_count', 'overdue_count', 'partial_count', 'undated_paid_count', 'unclassified_count'] as $field) {
            $result[$field] = (int) $row->$field;
        }
        // No partial payment amount exists on invoices. Do not invent a paid
        // amount or treat a partially paid invoice's full face value as debt.
        $result['fully_paid_rate'] = $result['issued_count'] ? round(100 * $result['paid_count'] / $result['issued_count'], 1) : null;
        if ($withTrend) {
            $result['trend'] = $this->trend($period, $amount);
        }

        return $result;
    }

    private function trend(string $period, string $amount): array
    {
        $issueDate = 'COALESCE(issue_date, issued_at, created_at)';
        $issued = $this->issued()->selectRaw("SUBSTR($issueDate, 1, 7) AS bucket, SUM($amount) AS amount")
            ->whereNotNull(DB::raw($issueDate));
        $paid = $this->issued()->where('status', 'paid')->whereNotNull('payment_date')
            ->selectRaw("SUBSTR(payment_date, 1, 7) AS bucket, SUM($amount) AS amount");
        $now = CarbonImmutable::now()->startOfMonth();
        if ($period !== 'all') {
            $start = $now->subMonths((int) $period - 1);
            $end = $now->endOfMonth();
            $issued->whereBetween(DB::raw($issueDate), [$start, $end]);
            $paid->whereBetween('payment_date', [$start, $end]);
        }
        $issued = $issued->groupByRaw("SUBSTR($issueDate, 1, 7)")->pluck('amount', 'bucket')->all();
        $paid = $paid->groupByRaw('SUBSTR(payment_date, 1, 7)')->pluck('amount', 'bucket')->all();
        $keys = array_keys($issued + $paid);
        sort($keys);
        if ($period === 'all') {
            $start = $keys ? CarbonImmutable::parse($keys[0].'-01')->startOfMonth() : $now->subMonths(11);
            $end = $keys ? max($now, CarbonImmutable::parse(end($keys).'-01')) : $now;
        }
        $yearly = $period === 'all' && $start->diffInMonths($end) >= 24;
        $cursor = $yearly ? $start->startOfYear() : $start;
        $points = [];
        while ($cursor <= $end) {
            $key = $cursor->format($yearly ? 'Y' : 'Y-m');
            $values = ['bucket' => $key, 'issued' => 0, 'paid' => 0];
            foreach (['issued' => $issued, 'paid' => $paid] as $series => $buckets) {
                foreach ($buckets as $month => $value) {
                    if (str_starts_with($month, $key)) {
                        $values[$series] += (float) $value;
                    }
                }
                $values[$series] = round($values[$series], 2);
            }
            $points[] = $values;
            $cursor = $yearly ? $cursor->addYear() : $cursor->addMonth();
        }

        return ['period' => $period, 'granularity' => $yearly ? 'year' : 'month',
            'from' => $start->toDateString(), 'to' => $end->endOfMonth()->toDateString(), 'points' => $points,
            'issued' => round(array_sum(array_column($points, 'issued')), 2),
            'paid' => round(array_sum(array_column($points, 'paid')), 2)];
    }

    private function cash(): array
    {
        $totals = Voucher::whereIn('status', ['active', 'completed'])->selectRaw('type, SUM(amount) AS amount')
            ->groupBy('type')->pluck('amount', 'type');
        $receipts = round((float) ($totals['receipt'] ?? 0), 2);
        $payments = round((float) ($totals['payment'] ?? 0), 2);

        return ['receipts' => $receipts, 'payments' => $payments, 'net' => round($receipts - $payments, 2)];
    }

    /** Records with no verifiable association remain visible only system-wide. */
    public function unassignedFinancial(array $associationIds): array
    {
        $query = Invoice::query();
        foreach ($associationIds as $id) {
            $query->whereNotIn('invoices.id', app(AssociationScope::class)->ids('invoices', $id));
        }

        return ['count' => $query->count()];
    }
}
