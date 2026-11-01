<?php

namespace App\Services;

use App\Models\Association;
use App\Models\Setting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Read-only calculations shared by the executive home, workspace and follow-up. */
final class MeetingFollowup
{
    public const DEFAULTS = [
        'count' => 2, 'fiscalMonth' => 12, 'midMonth' => 6, 'yearEndMonth' => 12,
        'yearEndEnabled' => true, 'offsets' => [30, 14, 7], 'urgent' => 5,
        'recipients' => ['chair', 'manager'], 'channels' => ['inapp'], 'auto' => true,
        'fiscalOverrides' => [],
    ];

    public function settings(): array
    {
        return array_replace(self::DEFAULTS, Setting::getByKey('followup_settings'));
    }

    public function window(int $year, int $month): array
    {
        $end = CarbonImmutable::create($month > 9 ? $year - 1 : $year, $month, 1, 12, 0, 0, 'Asia/Riyadh')->endOfMonth();

        return ['end' => $end->toDateString(), 'start' => $end->addDay()->toDateString(),
            'deadline' => $end->startOfMonth()->addMonthsNoOverflow(3)->endOfMonth()->toDateString()];
    }

    public function plan(Association $association, Collection $meetings, int $year, ?array $settings = null): array
    {
        $s = $settings ?? $this->settings();
        $fiscal = (int) ($s['fiscalOverrides'][(string) $association->id] ?? $s['fiscalMonth']);
        $window = $this->window($year, $fiscal);
        $today = CarbonImmutable::now('Asia/Riyadh')->toDateString();
        $monthEnd = fn ($month) => CarbonImmutable::create($year, $month, 1, 12, 0, 0, 'Asia/Riyadh')->endOfMonth()->toDateString();
        $slots = [
            ['kind' => 'annual', 'start' => $window['start'], 'deadline' => $window['deadline'], 'required' => true],
            ['kind' => 'midyear', 'start' => "$year-01-01", 'deadline' => $monthEnd($s['midMonth']), 'required' => true],
        ];
        if ($s['yearEndEnabled'] || $s['count'] >= 3) {
            $slots[] = ['kind' => 'yearend', 'start' => "$year-01-01", 'deadline' => $monthEnd($s['yearEndMonth']), 'required' => $s['count'] >= 3];
        }
        if ($s['count'] >= 4) {
            $slots[] = ['kind' => 'extra', 'start' => "$year-01-01", 'deadline' => $monthEnd(9), 'required' => true];
        }
        $meetings = $meetings->filter(fn ($m) => $m->association_id === $association->id && $m->scheduled_at?->copy()->timezone('Asia/Riyadh')->year === $year);
        // Existing completed meetings with minutes remain recognised; no data is rewritten.
        $documented = $meetings->filter(fn ($m) => in_array($m->status, ['completed', 'held']) && filled(trim((string) $m->minutes)) && $m->scheduled_at->copy()->timezone('Asia/Riyadh')->toDateString() <= $today);
        foreach ($slots as &$slot) {
            $eligible = $documented->where('type', $slot['kind'])->sortBy('scheduled_at');
            $inWindow = $eligible->first(fn ($m) => ($date = $m->scheduled_at->copy()->timezone('Asia/Riyadh')->toDateString()) >= $slot['start'] && $date <= $slot['deadline']);
            $held = $inWindow ?? $eligible->first();
            $planned = $meetings->where('type', $slot['kind'])->where('status', 'scheduled')->sortBy('scheduled_at')->first();
            $slot['held'] = $held?->only(['id', 'title', 'scheduled_at']);
            $slot['planned'] = $planned?->only(['id', 'title', 'scheduled_at']);
            $slot['late'] = (bool) ($held && ! $inWindow);
        }
        unset($slot);
        $annual = $slots[0];
        $bad = $annual['deadline'] < $today && (! $annual['held'] || $annual['late']);
        $missed = collect($slots)->contains(fn ($slot) => $slot['required'] && (! $slot['held'] || $slot['late']) && $slot['deadline'] < $today);
        $scheduled = $meetings->where('status', 'scheduled')->count();
        $level = $bad ? 'bad' : ($missed ? 'warn' : ($documented->count() >= $s['count'] && $annual['held'] && ! $annual['late'] ? 'ok' : ($scheduled ? 'info' : 'warn')));

        return ['association_id' => $association->id, 'name' => $association->name, 'name_en' => $association->name_en,
            'fiscalMonth' => $fiscal, 'fiscalOverride' => isset($s['fiscalOverrides'][(string) $association->id]),
            'year' => $year, 'held' => $documented->count(), 'required' => (int) $s['count'],
            'scheduled' => $scheduled, 'level' => $level, 'annual' => $annual, 'slots' => $slots,
            'next' => collect($slots)->first(fn ($slot) => $slot['required'] && ! $slot['held']) ?? collect($slots)->first(fn ($slot) => ! $slot['held'])];
    }

    /** Shared owners contribute only their actual percentage of each unit's area. */
    public function ownership(int $associationId): array
    {
        $units = Unit::with('owners:id,full_name')->whereHas('property', fn ($q) => $q->where('association_id', $associationId))->get();
        $total = 0.0;
        $owners = [];
        $incomplete = false;
        foreach ($units as $unit) {
            $area = (float) $unit->area;
            $total += max(0, $area);
            if ($area <= 0 || $unit->owners->isEmpty()) {
                $incomplete = true;
            }
            $sum = 0.0;
            foreach ($unit->owners as $owner) {
                $ratio = $owner->pivot->ownership_ratio;
                if ($ratio === null && $unit->owners->count() === 1) {
                    $ratio = 100;
                }
                if ($ratio === null || (float) $ratio <= 0 || (float) $ratio > 100) {
                    $incomplete = true;

                    continue;
                }
                $sum += (float) $ratio;
                $owners[$owner->id] ??= ['owner_id' => $owner->id, 'name' => $owner->full_name, 'area' => 0.0];
                $owners[$owner->id]['area'] += max(0, $area) * (float) $ratio / 100;
            }
            if (abs($sum - 100) > 0.01) {
                $incomplete = true;
            }
        }

        return ['total_area' => round($total, 4), 'owners' => array_values($owners), 'incomplete' => $incomplete || $total <= 0];
    }
}
