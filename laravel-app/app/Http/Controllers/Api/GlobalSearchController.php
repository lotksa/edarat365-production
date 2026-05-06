<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Owner, Association, Property, Unit, Contract, Meeting, Vote, Invoice, Voucher, MaintenanceRequest, Vehicle, LegalCase, Setting};
use Illuminate\Http\{Request, JsonResponse};

class GlobalSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));
        if (mb_strlen($q) < 1) {
            return response()->json(['query' => $q, 'groups' => []]);
        }

        $settings = Setting::getByKey('search_settings', [
            'sections' => [
                'owners' => true, 'associations' => true, 'properties' => true,
                'units' => true, 'contracts' => true, 'meetings' => true,
                'votes' => true, 'invoices' => true, 'vouchers' => true,
                'maintenance' => true, 'vehicles' => true, 'legal_cases' => true,
            ],
            'ai_enabled' => false,
        ]);

        $sections = $settings['sections'] ?? [];
        $limit = 5;
        $groups = [];

        if ($sections['owners'] ?? true) {
            $items = Owner::where('full_name', 'LIKE', "%{$q}%")
                ->orWhere('national_id', 'LIKE', "%{$q}%")
                ->orWhere('phone', 'LIKE', "%{$q}%")
                ->orWhere('email', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->full_name, 'subtitle' => $r->national_id, 'url' => "/owners/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'owners', 'label_ar' => 'الملاك', 'label_en' => 'Owners', 'icon' => 'owners', 'items' => $items];
            }
        }

        if ($sections['associations'] ?? true) {
            $items = Association::where('name', 'LIKE', "%{$q}%")
                ->orWhere('registration_number', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->name, 'subtitle' => $r->registration_number, 'url' => "/associations/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'associations', 'label_ar' => 'الجمعيات', 'label_en' => 'Associations', 'icon' => 'building', 'items' => $items];
            }
        }

        if ($sections['properties'] ?? true) {
            $items = Property::where('name', 'LIKE', "%{$q}%")
                ->orWhere('property_code', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->name, 'subtitle' => $r->property_code, 'url' => "/properties/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'properties', 'label_ar' => 'العقارات', 'label_en' => 'Properties', 'icon' => 'contract', 'items' => $items];
            }
        }

        if ($sections['units'] ?? true) {
            $items = Unit::where('unit_number', 'LIKE', "%{$q}%")
                ->orWhere('unit_code', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->unit_number, 'subtitle' => $r->unit_code, 'url' => "/units/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'units', 'label_ar' => 'الوحدات', 'label_en' => 'Units', 'icon' => 'maintenance', 'items' => $items];
            }
        }

        if ($sections['contracts'] ?? true) {
            $items = Contract::where('contract_number', 'LIKE', "%{$q}%")
                ->orWhere('tenant_name', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->contract_number ?: "#{$r->id}", 'subtitle' => $r->tenant_name, 'url' => "/contracts/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'contracts', 'label_ar' => 'العقود', 'label_en' => 'Contracts', 'icon' => 'contract', 'items' => $items];
            }
        }

        if ($sections['meetings'] ?? true) {
            $items = Meeting::where('title', 'LIKE', "%{$q}%")
                ->orWhere('meeting_number', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->title, 'subtitle' => $r->meeting_number, 'url' => "/meetings/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'meetings', 'label_ar' => 'الاجتماعات', 'label_en' => 'Meetings', 'icon' => 'meeting', 'items' => $items];
            }
        }

        if ($sections['votes'] ?? true) {
            $items = Vote::where('title', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->title, 'subtitle' => $r->vote_type, 'url' => "/votes/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'votes', 'label_ar' => 'التصويتات', 'label_en' => 'Votes', 'icon' => 'meeting', 'items' => $items];
            }
        }

        if ($sections['invoices'] ?? true) {
            $items = Invoice::where('invoice_number', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->invoice_number ?: "#{$r->id}", 'subtitle' => ($r->total_amount ?? 0) . ' SAR', 'url' => "/invoices/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'invoices', 'label_ar' => 'الفواتير', 'label_en' => 'Invoices', 'icon' => 'invoice', 'items' => $items];
            }
        }

        if ($sections['vouchers'] ?? true) {
            $items = Voucher::where('voucher_number', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->voucher_number ?: "#{$r->id}", 'subtitle' => $r->type === 'receipt' ? 'سند قبض' : 'سند صرف', 'url' => "/vouchers/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'vouchers', 'label_ar' => 'السندات', 'label_en' => 'Vouchers', 'icon' => 'invoice', 'items' => $items];
            }
        }

        if ($sections['maintenance'] ?? true) {
            $items = MaintenanceRequest::where('title', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->title, 'subtitle' => $r->priority, 'url' => "/maintenance/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'maintenance', 'label_ar' => 'الصيانة', 'label_en' => 'Maintenance', 'icon' => 'maintenance', 'items' => $items];
            }
        }

        if ($sections['vehicles'] ?? true) {
            $items = Vehicle::where('plate_number', 'LIKE', "%{$q}%")
                ->orWhere('brand', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->plate_number, 'subtitle' => trim(($r->brand ?? '') . ' ' . ($r->model ?? '')), 'url' => "/vehicles/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'vehicles', 'label_ar' => 'المركبات', 'label_en' => 'Vehicles', 'icon' => 'transaction', 'items' => $items];
            }
        }

        if ($sections['legal_cases'] ?? true) {
            $items = LegalCase::where('title', 'LIKE', "%{$q}%")
                ->orWhere('case_number', 'LIKE', "%{$q}%")
                ->limit($limit)->get()
                ->map(fn($r) => ['id' => $r->id, 'title' => $r->title, 'subtitle' => $r->case_number, 'url' => "/legal-cases/{$r->id}"]);
            if ($items->count()) {
                $groups[] = ['type' => 'legal_cases', 'label_ar' => 'القضايا', 'label_en' => 'Legal Cases', 'icon' => 'legal', 'items' => $items];
            }
        }

        return response()->json([
            'query' => $q,
            'groups' => $groups,
            'ai_enabled' => $settings['ai_enabled'] ?? false,
        ]);
    }

    public function aiSearch(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));
        $settings = Setting::getByKey('search_settings', ['ai_enabled' => false]);

        if (!($settings['ai_enabled'] ?? false) || mb_strlen($q) < 2) {
            return response()->json(['suggestion' => null]);
        }

        $allResults = $this->search($request)->getData(true);
        $totalItems = collect($allResults['groups'] ?? [])->sum(fn($g) => count($g['items']));
        $groupNames = collect($allResults['groups'] ?? [])->pluck('label_ar')->join('، ');

        $suggestion = $totalItems > 0
            ? "تم العثور على {$totalItems} نتيجة في: {$groupNames}"
            : 'لم يتم العثور على نتائج. جرّب البحث بكلمات مختلفة.';

        return response()->json([
            'suggestion' => $suggestion,
            'total' => $totalItems,
        ]);
    }
}
