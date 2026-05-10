<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{
    Owner, Tenant, Association, Property, Unit, Contract,
    Meeting, Vote, Invoice, Voucher, MaintenanceRequest,
    Vehicle, ParkingSpot, LegalCase, LegalRepresentative,
    PropertyManager, AssociationManager, User, Setting
};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\Log;

/**
 * Comprehensive global search.
 *
 * Searches every meaningful business entity in the platform by:
 *   - free text (names, titles, descriptions)
 *   - identifiers (national IDs via blind-index, account numbers,
 *     contract/invoice/voucher/case/meeting/vote numbers, plate
 *     numbers, parking numbers, etc.)
 *   - phone numbers (with prefix/format normalization)
 *   - emails
 *
 * Design notes:
 *   - Each section is a self-contained closure with its own try/catch so
 *     one slow/missing table can never blank out the whole result set.
 *   - All multi-column searches use grouped WHERE clauses
 *     (`->where(fn($q) => $q->where(...)->orWhere(...))`) so the OR-chain
 *     never bleeds into outer scopes or future joins.
 *   - Encrypted PII columns (e.g. `national_id`, `license_number`) are
 *     looked up via their blind-index hash column for exact matches.
 *   - Phone numbers are normalized to digits-only, then queried with
 *     `LIKE` against any plaintext phone column we know about.
 *   - Per-section results are capped at `$limit` (default 6); the
 *     response also exposes `total` per group so the frontend can render
 *     a "View all N matches →" link to the corresponding list page.
 */
class GlobalSearchController extends Controller
{
    /** Hard cap per section to keep responses small and fast. */
    private const SECTION_LIMIT = 6;

    /** Minimum query length to trigger search (avoids accidental scans). */
    private const MIN_LENGTH = 1;

    public function search(Request $request): JsonResponse
    {
        $raw = (string) $request->input('q', '');
        $q = trim($raw);
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return response()->json([
                'query' => $q,
                'groups' => [],
                'total' => 0,
                'ai_enabled' => false,
            ]);
        }

        $settings = Setting::getByKey('search_settings', [
            'sections' => [
                'owners' => true, 'tenants' => true, 'users' => true,
                'associations' => true, 'properties' => true, 'units' => true,
                'contracts' => true, 'meetings' => true, 'votes' => true,
                'invoices' => true, 'vouchers' => true, 'maintenance' => true,
                'vehicles' => true, 'parking_spots' => true,
                'legal_cases' => true, 'legal_representatives' => true,
                'property_managers' => true, 'association_managers' => true,
                'bookings' => true,
            ],
            'ai_enabled' => false,
        ]);

        $sections = (array) ($settings['sections'] ?? []);
        $limit = (int) $request->input('limit', self::SECTION_LIMIT);
        $limit = max(1, min(20, $limit));

        // Pre-compute normalized query forms so each section can pick.
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $isDigitsOnly = $digits !== '' && $q === $digits;
        $isEmailLike = str_contains($q, '@');
        $phoneVariants = $this->phoneVariants($digits);

        $groups = [];

        // ── Owners ──────────────────────────────────────────────────────
        if ($sections['owners'] ?? true) {
            $groups[] = $this->safeSection('owners', 'الملاك', 'Owners', 'owners',
                fn() => $this->searchOwners($q, $digits, $phoneVariants, $isDigitsOnly, $limit)
            );
        }

        // ── Tenants ─────────────────────────────────────────────────────
        if ($sections['tenants'] ?? true) {
            $groups[] = $this->safeSection('tenants', 'المستأجرون', 'Tenants', 'user',
                fn() => $this->searchTenants($q, $digits, $phoneVariants, $isDigitsOnly, $limit)
            );
        }

        // ── Users (system accounts) ─────────────────────────────────────
        if ($sections['users'] ?? true) {
            $groups[] = $this->safeSection('users', 'المستخدمون', 'Users', 'user',
                fn() => $this->searchUsers($q, $phoneVariants, $isEmailLike, $limit)
            );
        }

        // ── Associations ────────────────────────────────────────────────
        if ($sections['associations'] ?? true) {
            $groups[] = $this->safeSection('associations', 'الجمعيات', 'Associations', 'building',
                fn() => $this->searchAssociations($q, $phoneVariants, $isEmailLike, $limit)
            );
        }

        // ── Properties ──────────────────────────────────────────────────
        if ($sections['properties'] ?? true) {
            $groups[] = $this->safeSection('properties', 'العقارات', 'Properties', 'contract',
                fn() => $this->searchProperties($q, $limit)
            );
        }

        // ── Units ───────────────────────────────────────────────────────
        if ($sections['units'] ?? true) {
            $groups[] = $this->safeSection('units', 'الوحدات', 'Units', 'maintenance',
                fn() => $this->searchUnits($q, $limit)
            );
        }

        // ── Contracts ───────────────────────────────────────────────────
        if ($sections['contracts'] ?? true) {
            $groups[] = $this->safeSection('contracts', 'العقود', 'Contracts', 'contract',
                fn() => $this->searchContracts($q, $phoneVariants, $isEmailLike, $limit)
            );
        }

        // ── Meetings ────────────────────────────────────────────────────
        if ($sections['meetings'] ?? true) {
            $groups[] = $this->safeSection('meetings', 'الاجتماعات', 'Meetings', 'meeting',
                fn() => $this->searchMeetings($q, $limit)
            );
        }

        // ── Votes ───────────────────────────────────────────────────────
        if ($sections['votes'] ?? true) {
            $groups[] = $this->safeSection('votes', 'التصويتات', 'Votes', 'meeting',
                fn() => $this->searchVotes($q, $limit)
            );
        }

        // ── Invoices ────────────────────────────────────────────────────
        if ($sections['invoices'] ?? true) {
            $groups[] = $this->safeSection('invoices', 'الفواتير', 'Invoices', 'invoice',
                fn() => $this->searchInvoices($q, $limit)
            );
        }

        // ── Vouchers ────────────────────────────────────────────────────
        if ($sections['vouchers'] ?? true) {
            $groups[] = $this->safeSection('vouchers', 'سندات القبض والصرف', 'Vouchers', 'invoice',
                fn() => $this->searchVouchers($q, $limit)
            );
        }

        // ── Maintenance Requests ────────────────────────────────────────
        if ($sections['maintenance'] ?? true) {
            $groups[] = $this->safeSection('maintenance', 'طلبات الصيانة', 'Maintenance', 'maintenance',
                fn() => $this->searchMaintenance($q, $limit)
            );
        }

        // ── Vehicles ────────────────────────────────────────────────────
        if ($sections['vehicles'] ?? true) {
            $groups[] = $this->safeSection('vehicles', 'المركبات', 'Vehicles', 'transaction',
                fn() => $this->searchVehicles($q, $limit)
            );
        }

        // ── Parking Spots ───────────────────────────────────────────────
        if ($sections['parking_spots'] ?? true) {
            $groups[] = $this->safeSection('parking_spots', 'مواقف السيارات', 'Parking Spots', 'transaction',
                fn() => $this->searchParkingSpots($q, $limit)
            );
        }

        // ── Legal Cases ─────────────────────────────────────────────────
        if ($sections['legal_cases'] ?? true) {
            $groups[] = $this->safeSection('legal_cases', 'القضايا القانونية', 'Legal Cases', 'legal',
                fn() => $this->searchLegalCases($q, $limit)
            );
        }

        // ── Legal Representatives ───────────────────────────────────────
        if ($sections['legal_representatives'] ?? true) {
            $groups[] = $this->safeSection('legal_representatives', 'الممثلون القانونيون', 'Legal Representatives', 'legal',
                fn() => $this->searchLegalRepresentatives($q, $digits, $phoneVariants, $isDigitsOnly, $limit)
            );
        }

        // ── Property Managers ───────────────────────────────────────────
        if ($sections['property_managers'] ?? true) {
            $groups[] = $this->safeSection('property_managers', 'مدراء العقار', 'Property Managers', 'user',
                fn() => $this->searchPropertyManagers($q, $digits, $phoneVariants, $isDigitsOnly, $limit)
            );
        }

        // ── Association Managers ────────────────────────────────────────
        if ($sections['association_managers'] ?? true) {
            $groups[] = $this->safeSection('association_managers', 'رؤساء الجمعيات', 'Association Managers', 'user',
                fn() => $this->searchAssociationManagers($q, $digits, $phoneVariants, $isDigitsOnly, $limit)
            );
        }

        // Drop empty sections so the frontend doesn't render empty headers.
        $groups = array_values(array_filter($groups, fn($g) => $g && !empty($g['items'])));
        $total = array_sum(array_map(fn($g) => count($g['items']), $groups));

        return response()->json([
            'query' => $q,
            'groups' => $groups,
            'total' => $total,
            'ai_enabled' => (bool) ($settings['ai_enabled'] ?? false),
        ]);
    }

    /** Backwards-compatible AI hint endpoint (used by the spotlight modal). */
    public function aiSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $settings = Setting::getByKey('search_settings', ['ai_enabled' => false]);

        if (!($settings['ai_enabled'] ?? false) || mb_strlen($q) < 2) {
            return response()->json(['suggestion' => null]);
        }

        $payload = $this->search($request)->getData(true);
        $total = (int) ($payload['total'] ?? 0);
        $labels = collect($payload['groups'] ?? [])->pluck('label_ar')->filter()->join('، ');

        $suggestion = $total > 0
            ? "تم العثور على {$total} نتيجة في: {$labels}"
            : 'لم يتم العثور على نتائج. جرّب البحث بكلمات مختلفة أو رقم هوية/جوال.';

        return response()->json(['suggestion' => $suggestion, 'total' => $total]);
    }

    // ════════════════════════════════════════════════════════════════════
    //                            SECTION IMPLEMENTATIONS
    // ════════════════════════════════════════════════════════════════════

    private function searchOwners(string $q, string $digits, array $phoneVariants, bool $isDigitsOnly, int $limit): array
    {
        $like = "%{$q}%";
        $base = Owner::query()->select(['id', 'full_name', 'phone', 'email', 'account_number', 'status']);

        $base->where(function ($w) use ($like, $digits, $phoneVariants, $isDigitsOnly) {
            $w->where('full_name', 'LIKE', $like)
              ->orWhere('email', 'LIKE', $like);

            // Phone variants (plaintext column).
            foreach ($phoneVariants as $pv) {
                $w->orWhere('phone', 'LIKE', "%{$pv}%");
            }

            // Account number is plaintext numeric.
            if ($digits !== '') {
                $w->orWhere('account_number', 'LIKE', "%{$digits}%");
            }

            // National ID is encrypted → use the blind hash for exact match.
            if ($isDigitsOnly && mb_strlen($digits) >= 8) {
                $w->orWhere('national_id_hash', Owner::blindHash($digits));
            }
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->account_number ? '#' . $r->account_number : null,
                    $r->phone ?: null,
                    $r->email ?: null,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->full_name ?: ('مالك #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/owners/{$r->id}",
                ];
            })->all();
    }

    private function searchTenants(string $q, string $digits, array $phoneVariants, bool $isDigitsOnly, int $limit): array
    {
        $like = "%{$q}%";
        $base = Tenant::query()->select(['id', 'full_name', 'phone', 'email', 'status']);

        $base->where(function ($w) use ($like, $digits, $phoneVariants, $isDigitsOnly) {
            $w->where('full_name', 'LIKE', $like)
              ->orWhere('email', 'LIKE', $like);

            foreach ($phoneVariants as $pv) {
                $w->orWhere('phone', 'LIKE', "%{$pv}%");
            }

            if ($isDigitsOnly && mb_strlen($digits) >= 8) {
                $w->orWhere('national_id_hash', Tenant::blindHash($digits));
            }
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([$r->phone ?: null, $r->email ?: null])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->full_name ?: ('مستأجر #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    // No dedicated tenant detail page yet → land in contracts list filtered by tenant.
                    'url' => "/contracts?tenant_id={$r->id}",
                ];
            })->all();
    }

    private function searchUsers(string $q, array $phoneVariants, bool $isEmailLike, int $limit): array
    {
        $like = "%{$q}%";
        $base = User::query()->select(['id', 'name', 'email', 'phone', 'is_active']);

        $base->where(function ($w) use ($like, $phoneVariants) {
            $w->where('name', 'LIKE', $like)
              ->orWhere('email', 'LIKE', $like);
            foreach ($phoneVariants as $pv) {
                $w->orWhere('phone', 'LIKE', "%{$pv}%");
            }
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([$r->email ?: null, $r->phone ?: null])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->name ?: ('مستخدم #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->is_active ? 'نشط' : 'موقوف',
                    'url' => "/users?focus={$r->id}",
                ];
            })->all();
    }

    private function searchAssociations(string $q, array $phoneVariants, bool $isEmailLike, int $limit): array
    {
        $like = "%{$q}%";
        $base = Association::query()->select([
            'id', 'name', 'name_en', 'registration_number', 'association_number',
            'unified_number', 'phone', 'email', 'status',
        ]);

        $base->where(function ($w) use ($like, $phoneVariants) {
            $w->where('name', 'LIKE', $like)
              ->orWhere('name_en', 'LIKE', $like)
              ->orWhere('registration_number', 'LIKE', $like)
              ->orWhere('association_number', 'LIKE', $like)
              ->orWhere('unified_number', 'LIKE', $like)
              ->orWhere('email', 'LIKE', $like);
            foreach ($phoneVariants as $pv) {
                $w->orWhere('phone', 'LIKE', "%{$pv}%");
            }
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->registration_number ? 'سجل: ' . $r->registration_number : null,
                    $r->association_number ?: null,
                    $r->phone ?: null,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->name ?: ($r->name_en ?: 'جمعية #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/associations/{$r->id}",
                ];
            })->all();
    }

    private function searchProperties(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = Property::query()->select([
            'id', 'property_number', 'name', 'type', 'city', 'district', 'status', 'association_id',
        ]);

        $base->where(function ($w) use ($like) {
            $w->where('name', 'LIKE', $like)
              ->orWhere('property_number', 'LIKE', $like)
              ->orWhere('plot_number', 'LIKE', $like)
              ->orWhere('deed_number', 'LIKE', $like)
              ->orWhere('address', 'LIKE', $like)
              ->orWhere('city', 'LIKE', $like)
              ->orWhere('district', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->property_number ? '#' . $r->property_number : null,
                    $r->type ?: null,
                    collect([$r->city, $r->district])->filter()->join(' • ') ?: null,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->name ?: ('عقار #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/properties/{$r->id}",
                ];
            })->all();
    }

    private function searchUnits(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = Unit::query()
            ->with(['property:id,name'])
            ->select(['id', 'property_id', 'unit_code', 'unit_number', 'unit_type', 'building_name', 'floor_number', 'status']);

        $base->where(function ($w) use ($like) {
            $w->where('unit_number', 'LIKE', $like)
              ->orWhere('unit_code', 'LIKE', $like)
              ->orWhere('building_name', 'LIKE', $like)
              ->orWhere('deed_number', 'LIKE', $like)
              ->orWhere('description', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->property?->name ? 'العقار: ' . $r->property->name : null,
                    $r->building_name ? 'مبنى: ' . $r->building_name : null,
                    $r->floor_number !== null ? 'الدور: ' . $r->floor_number : null,
                    $r->unit_type ?: null,
                ])->filter()->join(' • ');
                $title = $r->unit_number ?: ($r->unit_code ?: ('وحدة #' . $r->id));
                return [
                    'id' => $r->id,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/units/{$r->id}",
                ];
            })->all();
    }

    private function searchContracts(string $q, array $phoneVariants, bool $isEmailLike, int $limit): array
    {
        $like = "%{$q}%";
        $base = Contract::query()->select([
            'id', 'contract_number', 'contract_name', 'contract_type',
            'party1_name', 'party2_name', 'tenant_name',
            'party1_phone', 'party2_phone', 'party1_email', 'party2_email',
            'start_date', 'end_date', 'rental_amount', 'status',
        ]);

        $base->where(function ($w) use ($like, $phoneVariants) {
            $w->where('contract_number', 'LIKE', $like)
              ->orWhere('contract_name', 'LIKE', $like)
              ->orWhere('tenant_name', 'LIKE', $like)
              ->orWhere('party1_name', 'LIKE', $like)
              ->orWhere('party2_name', 'LIKE', $like)
              ->orWhere('party1_email', 'LIKE', $like)
              ->orWhere('party2_email', 'LIKE', $like);
            foreach ($phoneVariants as $pv) {
                $w->orWhere('party1_phone', 'LIKE', "%{$pv}%")
                  ->orWhere('party2_phone', 'LIKE', "%{$pv}%");
            }
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $title = $r->contract_number ?: ($r->contract_name ?: ('عقد #' . $r->id));
                $parties = collect([$r->party1_name, $r->party2_name, $r->tenant_name])
                    ->filter()->unique()->take(2)->join(' ↔ ');
                $subtitle = collect([
                    $parties ?: null,
                    $r->contract_type ?: null,
                    $r->rental_amount ? number_format((float) $r->rental_amount, 2) . ' ر.س' : null,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/contracts/{$r->id}",
                ];
            })->all();
    }

    private function searchMeetings(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = Meeting::query()
            ->with(['association:id,name', 'property:id,name'])
            ->select([
                'id', 'meeting_number', 'title', 'type', 'scheduled_at',
                'association_id', 'property_id', 'status', 'location',
            ]);

        $base->where(function ($w) use ($like) {
            $w->where('title', 'LIKE', $like)
              ->orWhere('meeting_number', 'LIKE', $like)
              ->orWhere('agenda', 'LIKE', $like)
              ->orWhere('location', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->meeting_number ? '#' . $r->meeting_number : null,
                    $r->type ?: null,
                    $r->scheduled_at ? optional($r->scheduled_at)->format('Y-m-d H:i') : null,
                    $r->association?->name ?: $r->property?->name,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->title ?: ('اجتماع #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/meetings/{$r->id}",
                ];
            })->all();
    }

    private function searchVotes(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = Vote::query()
            ->with(['association:id,name'])
            ->select(['id', 'vote_number', 'title', 'description', 'status', 'association_id']);

        $base->where(function ($w) use ($like) {
            $w->where('title', 'LIKE', $like)
              ->orWhere('vote_number', 'LIKE', $like)
              ->orWhere('description', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->vote_number ? '#' . $r->vote_number : null,
                    $r->association?->name ?: null,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->title ?: ('تصويت #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/votes/{$r->id}",
                ];
            })->all();
    }

    private function searchInvoices(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = Invoice::query()
            ->with(['owner:id,full_name'])
            ->select([
                'id', 'invoice_number', 'invoice_type', 'owner_id',
                'total_amount', 'status', 'due_date', 'issue_date', 'description',
            ]);

        $base->where(function ($w) use ($like) {
            $w->where('invoice_number', 'LIKE', $like)
              ->orWhere('description', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->owner?->full_name ? 'العميل: ' . $r->owner->full_name : null,
                    $r->total_amount !== null ? number_format((float) $r->total_amount, 2) . ' ر.س' : null,
                    $r->due_date ? 'استحقاق: ' . optional($r->due_date)->format('Y-m-d') : null,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->invoice_number ?: ('فاتورة #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/invoices/{$r->id}",
                ];
            })->all();
    }

    private function searchVouchers(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = Voucher::query()
            ->with(['owner:id,full_name'])
            ->select([
                'id', 'voucher_number', 'type', 'owner_id',
                'amount', 'payment_method', 'payment_date', 'status', 'description',
            ]);

        $base->where(function ($w) use ($like) {
            $w->where('voucher_number', 'LIKE', $like)
              ->orWhere('description', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $kindAr = $r->type === 'receipt' ? 'سند قبض' : ($r->type === 'payment' ? 'سند صرف' : 'سند');
                $subtitle = collect([
                    $kindAr,
                    $r->owner?->full_name ?: null,
                    $r->amount !== null ? number_format((float) $r->amount, 2) . ' ر.س' : null,
                    $r->payment_date ? optional($r->payment_date)->format('Y-m-d') : null,
                ])->filter()->join(' • ');
                $base = $r->type === 'payment' ? '/vouchers/payments' : '/vouchers/receipts';
                return [
                    'id' => $r->id,
                    'title' => $r->voucher_number ?: ('سند #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "{$base}/{$r->id}",
                ];
            })->all();
    }

    private function searchMaintenance(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = MaintenanceRequest::query()
            ->with(['property:id,name', 'owner:id,full_name'])
            ->select([
                'id', 'title', 'type', 'category', 'priority',
                'status', 'property_id', 'owner_id', 'scheduled_date',
            ]);

        $base->where(function ($w) use ($like) {
            $w->where('title', 'LIKE', $like)
              ->orWhere('description', 'LIKE', $like)
              ->orWhere('category', 'LIKE', $like)
              ->orWhere('location', 'LIKE', $like)
              ->orWhere('assigned_to', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    'طلب #' . $r->id,
                    $r->type ?: null,
                    $r->priority ?: null,
                    $r->property?->name ?: ($r->owner?->full_name ?: null),
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->title ?: ('طلب صيانة #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/maintenance/{$r->id}",
                ];
            })->all();
    }

    private function searchVehicles(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = Vehicle::query()
            ->with(['owner:id,full_name'])
            ->select([
                'id', 'plate_number', 'car_type', 'car_model', 'car_color',
                'driver_name', 'status', 'owner_id', 'parking_type',
            ]);

        $base->where(function ($w) use ($like) {
            $w->where('plate_number', 'LIKE', $like)
              ->orWhere('car_type', 'LIKE', $like)
              ->orWhere('car_model', 'LIKE', $like)
              ->orWhere('driver_name', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $car = trim(($r->car_type ?? '') . ' ' . ($r->car_model ?? '') . ' ' . ($r->car_color ?? ''));
                $subtitle = collect([
                    $car ?: null,
                    $r->driver_name ? 'السائق: ' . $r->driver_name : null,
                    $r->owner?->full_name ? 'المالك: ' . $r->owner->full_name : null,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->plate_number ?: ('سيارة #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/vehicles/{$r->id}",
                ];
            })->all();
    }

    private function searchParkingSpots(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = ParkingSpot::query()
            ->with(['property:id,name', 'association:id,name'])
            ->select(['id', 'parking_number', 'parking_type', 'status', 'property_id', 'association_id']);

        $base->where(function ($w) use ($like) {
            $w->where('parking_number', 'LIKE', $like)
              ->orWhere('parking_type', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->parking_type ?: null,
                    $r->property?->name ?: $r->association?->name,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->parking_number ?: ('موقف #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/parking-spots/{$r->id}",
                ];
            })->all();
    }

    private function searchLegalCases(string $q, int $limit): array
    {
        $like = "%{$q}%";
        $base = LegalCase::query()
            ->with(['property:id,name', 'owner:id,full_name'])
            ->select([
                'id', 'case_number', 'title', 'case_type', 'status', 'priority',
                'court_name', 'plaintiff', 'defendant', 'lawyer_name',
                'property_id', 'owner_id', 'hearing_date',
            ]);

        $base->where(function ($w) use ($like) {
            $w->where('case_number', 'LIKE', $like)
              ->orWhere('title', 'LIKE', $like)
              ->orWhere('plaintiff', 'LIKE', $like)
              ->orWhere('defendant', 'LIKE', $like)
              ->orWhere('lawyer_name', 'LIKE', $like)
              ->orWhere('court_name', 'LIKE', $like)
              ->orWhere('description', 'LIKE', $like);
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->case_number ? '#' . $r->case_number : null,
                    $r->case_type ?: null,
                    $r->court_name ?: null,
                    $r->property?->name ?: ($r->owner?->full_name ?: null),
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->title ?: ('قضية #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/legal-cases/{$r->id}",
                ];
            })->all();
    }

    private function searchLegalRepresentatives(string $q, string $digits, array $phoneVariants, bool $isDigitsOnly, int $limit): array
    {
        $like = "%{$q}%";
        $base = LegalRepresentative::query()->select([
            'id', 'name', 'email', 'phone', 'specialty', 'firm_name', 'status',
        ]);

        $base->where(function ($w) use ($like, $phoneVariants, $isDigitsOnly, $digits) {
            $w->where('name', 'LIKE', $like)
              ->orWhere('email', 'LIKE', $like)
              ->orWhere('firm_name', 'LIKE', $like)
              ->orWhere('specialty', 'LIKE', $like);
            foreach ($phoneVariants as $pv) {
                $w->orWhere('phone', 'LIKE', "%{$pv}%");
            }
            if ($isDigitsOnly && mb_strlen($digits) >= 4) {
                $w->orWhere('license_number_hash', LegalRepresentative::blindHash($digits));
            }
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([
                    $r->specialty ?: null,
                    $r->firm_name ?: null,
                    $r->phone ?: null,
                ])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->name ?: ('ممثل قانوني #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/legal-representatives?focus={$r->id}",
                ];
            })->all();
    }

    private function searchPropertyManagers(string $q, string $digits, array $phoneVariants, bool $isDigitsOnly, int $limit): array
    {
        $like = "%{$q}%";
        $base = PropertyManager::query()->select(['id', 'full_name', 'phone', 'email', 'status']);

        $base->where(function ($w) use ($like, $phoneVariants, $isDigitsOnly, $digits) {
            $w->where('full_name', 'LIKE', $like)
              ->orWhere('email', 'LIKE', $like);
            foreach ($phoneVariants as $pv) {
                $w->orWhere('phone', 'LIKE', "%{$pv}%");
            }
            if ($isDigitsOnly && mb_strlen($digits) >= 8) {
                $w->orWhere('national_id_hash', PropertyManager::blindHash($digits));
            }
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([$r->phone ?: null, $r->email ?: null])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->full_name ?: ('مدير عقار #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/property-managers?focus={$r->id}",
                ];
            })->all();
    }

    private function searchAssociationManagers(string $q, string $digits, array $phoneVariants, bool $isDigitsOnly, int $limit): array
    {
        $like = "%{$q}%";
        $base = AssociationManager::query()->select(['id', 'full_name', 'phone', 'email', 'status']);

        $base->where(function ($w) use ($like, $phoneVariants, $isDigitsOnly, $digits) {
            $w->where('full_name', 'LIKE', $like)
              ->orWhere('email', 'LIKE', $like);
            foreach ($phoneVariants as $pv) {
                $w->orWhere('phone', 'LIKE', "%{$pv}%");
            }
            if ($isDigitsOnly && mb_strlen($digits) >= 8) {
                $w->orWhere('national_id_hash', AssociationManager::blindHash($digits));
            }
        });

        return $base->orderByDesc('id')->limit($limit)->get()
            ->map(function ($r) {
                $subtitle = collect([$r->phone ?: null, $r->email ?: null])->filter()->join(' • ');
                return [
                    'id' => $r->id,
                    'title' => $r->full_name ?: ('رئيس جمعية #' . $r->id),
                    'subtitle' => $subtitle,
                    'badge' => $r->status ?: null,
                    'url' => "/association-managers?focus={$r->id}",
                ];
            })->all();
    }

    // ════════════════════════════════════════════════════════════════════
    //                                HELPERS
    // ════════════════════════════════════════════════════════════════════

    /**
     * Runs a section closure inside a try/catch so a missing column or
     * broken FK never blanks out the entire response. On failure we log
     * and return an empty group (which the outer code then filters out).
     */
    private function safeSection(string $type, string $labelAr, string $labelEn, string $icon, \Closure $closure): ?array
    {
        try {
            $items = $closure();
        } catch (\Throwable $e) {
            Log::warning("GlobalSearch[{$type}] failed: " . $e->getMessage());
            return null;
        }

        return [
            'type' => $type,
            'label_ar' => $labelAr,
            'label_en' => $labelEn,
            'icon' => $icon,
            'items' => $items,
            'count' => count($items),
        ];
    }

    /**
     * Generates phone-number search variants from a digits-only query.
     *
     *   "0501234567" → ["0501234567", "501234567", "966501234567"]
     *   "501234567"  → ["501234567", "0501234567", "966501234567"]
     *   "+966501234567" → handled by caller stripping non-digits first
     *
     * Empty/short inputs return an empty array so the caller skips the
     * phone branch entirely.
     */
    private function phoneVariants(string $digits): array
    {
        if ($digits === '' || mb_strlen($digits) < 4) {
            return [];
        }

        $variants = [$digits];

        // Strip country code 966 if present.
        if (str_starts_with($digits, '966')) {
            $rest = substr($digits, 3);
            $variants[] = $rest;
            if (!str_starts_with($rest, '0')) {
                $variants[] = '0' . $rest;
            }
        }

        // Local 05XXXXXXXX ↔ international 966 5XXXXXXXX.
        if (str_starts_with($digits, '05')) {
            $variants[] = '966' . substr($digits, 1);
            $variants[] = substr($digits, 1); // 5XXXXXXXX
        } elseif (str_starts_with($digits, '5') && mb_strlen($digits) === 9) {
            $variants[] = '0' . $digits;
            $variants[] = '966' . $digits;
        }

        return array_values(array_unique($variants));
    }
}
