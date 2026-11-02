<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Invoice;
use App\Scopes\AssociationScope;
use App\Services\AssociationInvoiceSettings;
use App\Services\InvoiceQr;
use Illuminate\Http\JsonResponse;

final class PublicInvoiceSummaryController extends Controller
{
    public function show(string $token, InvoiceQr $qr, AssociationInvoiceSettings $settings): JsonResponse
    {
        $ids = $qr->verifyToken($token);
        abort_unless($ids, 404);
        // Only a valid server-issued capability grants access to this limited projection.
        $invoice = Invoice::withoutGlobalScope(AssociationScope::class)->findOrFail($ids['invoice_id']);
        $associationId = $invoice->association_id ?? $invoice->property?->association_id ?? $invoice->unit?->property?->association_id;
        abort_if($associationId && (int) $associationId !== $ids['association_id'], 404);
        $association = Association::withoutGlobalScope(AssociationScope::class)->findOrFail($ids['association_id']);
        $tax = $settings->forAssociation($association);

        // Never serialize the model or include customer/contact/location/attachment data.
        return response()->json(['data' => [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'is_tax_invoice' => $tax['vat_enabled'],
            'is_draft' => $invoice->status === 'draft',
            'is_cancelled' => $invoice->status === 'cancelled' || $invoice->cancelled_at !== null,
            'amount' => (float) $invoice->amount,
            'discount_amount' => (float) ($invoice->discount_amount ?? 0),
            'tax_amount' => (float) ($invoice->tax_amount ?? 0),
            'total_amount' => (float) $invoice->total_amount,
            'association' => $association->only(['name', 'name_en', 'logo', 'icon']),
        ]])->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
