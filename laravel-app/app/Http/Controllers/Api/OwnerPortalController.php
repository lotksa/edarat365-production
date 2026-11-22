<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Services\AssociationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnerPortalController extends Controller
{
    private function owner(Request $request): Owner
    {
        // No matching by email/phone or client-provided owner_id. Ambiguous legacy
        // links fail closed until an administrator resolves them explicitly.
        $profiles = Owner::where('user_id', $request->user()->id)->limit(2)->get();
        $context = app(AssociationContext::class);
        abort_unless($profiles->count() === 1 && $profiles[0]->status === 'active',
            403, $context->isEndowments() ? 'لم يتم ربط حسابك بوقف. تواصل مع الإدارة.' : 'لم يتم ربط حسابك بملف مالك نشط. تواصل مع الإدارة.');

        if ($context->isEndowments()) {
            $context->id = (int) $profiles[0]->association_id;
        }

        return $profiles[0];
    }

    private function unitQuery(Owner $owner): Builder
    {
        return Unit::query()
            ->when(app(AssociationContext::class)->isEndowments(),
                fn ($q) => $q->whereHas('property'),
                fn ($q) => $q->whereHas('owners', fn ($owners) => $owners->where('owners.id', $owner->id)))
            ->with(['property:id,name', 'owners' => fn ($q) => $q->where('owners.id', $owner->id)->select('owners.id')]);
    }

    private function invoiceQuery(Owner $owner): Builder
    {
        // A co-owner of the same unit must never see another owner's invoices.
        return Invoice::where('owner_id', $owner->id)->whereNotIn('status', ['draft'])
            ->with(['property:id,name', 'unit:id,unit_number']);
    }

    private function contractQuery(Owner $owner): Builder
    {
        return Contract::where('owner_id', $owner->id)->where('status', '!=', 'draft')
            ->with(['property:id,name', 'unit:id,unit_number']);
    }

    private function notificationQuery(Request $request, Owner $owner): Builder
    {
        return Notification::whereIn('audience', ['owner', 'user'])->where(function ($q) use ($request, $owner) {
            $q->where(fn ($q) => $q->where('owner_id', $owner->id)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $request->user()->id)))
                ->orWhere(fn ($q) => $q->where('user_id', $request->user()->id)->whereNull('owner_id'));
        });
    }

    public function overview(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $invoices = $this->invoiceQuery($owner);
        $unpaid = (clone $invoices)->whereNull('cancelled_at')->whereIn('status', ['unpaid', 'pending', 'overdue']);
        $endowment = app(AssociationContext::class)->isEndowments() ? $owner->association : null;

        return response()->json(['data' => [
            'owner' => $owner->only('full_name', 'account_number'),
            'summary' => [
                'units' => $this->unitQuery($owner)->count(),
                'invoices' => (clone $invoices)->count(),
                'contracts' => $this->contractQuery($owner)->count(),
                'unread_notifications' => $this->notificationQuery($request, $owner)->unread()->count(),
                'outstanding' => round((float) (clone $unpaid)->selectRaw('COALESCE(SUM(COALESCE(total_amount, amount + COALESCE(tax_amount, 0) - COALESCE(discount_amount, 0))), 0) AS outstanding')->value('outstanding'), 2),
                'overdue_count' => (clone $unpaid)->whereDate('due_date', '<', today())->count(),
                // Partial settlement amounts are not recorded on invoices. Present
                // a count separately rather than inventing a remaining balance.
                'partial_count' => (clone $invoices)->whereNull('cancelled_at')->where('status', 'partial')->count(),
            ] + ($endowment ? ['properties' => Property::count()] : []),
            'recent_invoices' => (clone $invoices)->latest('id')->limit(5)->get()->map($this->invoiceData(...)),
            'recent_notifications' => $this->notificationQuery($request, $owner)->latest('id')->limit(4)->get()->map($this->notificationData(...)),
        ] + ($endowment ? ['endowment' => $endowment->only('name', 'name_en', 'association_number', 'registration_number')] : [])]);
    }

    private function listing(Request $request, Builder $query, callable $serialize): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $page = $query->latest('id')->paginate($data['per_page'] ?? 12);

        return response()->json([
            'data' => $page->getCollection()->map($serialize),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function units(Request $request): JsonResponse
    {
        return $this->listing($request, $this->unitQuery($this->owner($request)), $this->unitData(...));
    }

    public function properties(Request $request): JsonResponse
    {
        $this->owner($request);

        return $this->listing($request, Property::withCount('units'), $this->propertyData(...));
    }

    public function property(Request $request, int $id): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => $this->propertyData(Property::withCount('units')->findOrFail($id))]);
    }

    private function propertyData(Property $property): array
    {
        return $property->only('id', 'name', 'property_number', 'type', 'status', 'units_count');
    }

    public function unit(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->unitData($this->unitQuery($this->owner($request))->findOrFail($id))]);
    }

    public function invoices(Request $request): JsonResponse
    {
        return $this->listing($request, $this->invoiceQuery($this->owner($request)), $this->invoiceData(...));
    }

    public function invoice(Request $request, int $id): JsonResponse
    {
        $invoice = $this->invoiceQuery($this->owner($request))->findOrFail($id);

        return response()->json(['data' => $this->invoiceData($invoice) + $invoice->only(
            'description', 'amount', 'tax_amount', 'vat_rate', 'discount_amount', 'line_items', 'payment_method'
        )]);
    }

    public function contracts(Request $request): JsonResponse
    {
        return $this->listing($request, $this->contractQuery($this->owner($request)), $this->contractData(...));
    }

    public function contract(Request $request, int $id): JsonResponse
    {
        $contract = $this->contractQuery($this->owner($request))->findOrFail($id);

        return response()->json(['data' => $this->contractData($contract) + $contract->only(
            'contract_type', 'contract_nature', 'party1_name', 'party2_name', 'preamble', 'contract_clauses', 'payment_type'
        )]);
    }

    public function notifications(Request $request): JsonResponse
    {
        return $this->listing($request, $this->notificationQuery($request, $this->owner($request)), $this->notificationData(...));
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $notification = $this->notificationQuery($request, $this->owner($request))->findOrFail($id);
        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json(['data' => $this->notificationData($notification)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->notificationQuery($request, $this->owner($request))->unread()->update(['read_at' => now()]);

        return response()->json(['message' => 'تم تحديد إشعاراتك كمقروءة.']);
    }

    private function unitData(Unit $unit): array
    {
        return $unit->only('id', 'unit_number', 'unit_code', 'unit_type', 'floor_number', 'area', 'status', 'bedrooms', 'bathrooms') + [
            'property_name' => $unit->property?->name,
            'ownership_ratio' => $unit->owners->first()?->pivot?->ownership_ratio,
        ];
    }

    private function invoiceData(Invoice $invoice): array
    {
        return $invoice->only('id', 'invoice_number', 'invoice_type', 'status', 'total_amount') + [
            'property_name' => $invoice->property?->name, 'unit_number' => $invoice->unit?->unit_number,
            'issue_date' => $invoice->issue_date?->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
            'payment_date' => $invoice->payment_date?->toDateString(),
        ];
    }

    private function contractData(Contract $contract): array
    {
        return $contract->only('id', 'contract_number', 'contract_name', 'status', 'rental_amount') + [
            'property_name' => $contract->property?->name, 'unit_number' => $contract->unit?->unit_number,
            'start_date' => $contract->start_date?->toDateString(), 'end_date' => $contract->end_date?->toDateString(),
        ];
    }

    private function notificationData(Notification $notification): array
    {
        // Never forward administrative links, arbitrary data or recipient IDs.
        return $notification->only('id', 'title_ar', 'title_en', 'body_ar', 'body_en', 'level') + [
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
