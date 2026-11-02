<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Association;
use App\Services\AssociationInvoiceSettings;
use App\Services\InvoiceQr;
use Illuminate\Http\JsonResponse;

class InvoicePdfController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with(['association', 'property', 'owner', 'unit', 'tenant'])->findOrFail($id);

        $associationBranding = app(\App\Services\AssociationBranding::class)->forRecord($invoice);
        $association = Association::findOrFail($associationBranding['id']);
        $tax = app(AssociationInvoiceSettings::class)->forAssociation($association);
        $qr = app(InvoiceQr::class);
        $invoiceDate = $invoice->issue_date?->toDateString() ?? $invoice->created_at?->toDateString();
        $totalWithVat = (float) ($invoice->total_amount ?? 0);
        $vatAmount = (float) ($invoice->tax_amount ?? 0);
        $vatEnabled = $tax['vat_enabled'];
        $zatcaQr = $qr->taxPayload($invoice, $tax);

        $lineItems = is_array($invoice->line_items) ? $invoice->line_items : [];

        return response()->json([
            'branding' => $associationBranding,
            'invoice' => [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'issue_date'     => $invoiceDate,
                'issued_at'      => $invoice->issued_at?->toIso8601String(),
                'due_date'       => $invoice->due_date?->toDateString(),
                'payment_date'   => $invoice->payment_date,
                'status'         => $invoice->status,
                'amount'         => (float) ($invoice->amount ?? 0),
                'vat_rate'       => (float) ($invoice->vat_rate ?? 0),
                'tax_amount'     => $vatAmount,
                'discount_amount'=> (float) ($invoice->discount_amount ?? 0),
                'total_amount'   => $totalWithVat,
                'line_items'     => $lineItems,
                'notes'          => $invoice->notes,
                'description'    => $invoice->description,
            ],
            // Customer block intentionally omits the national ID. ZATCA phase-2
            // does not require the buyer's national ID on a B2C tax invoice and
            // including it on a printable / shareable document leaks PII.
            'owner' => [
                'name'  => $invoice->owner?->full_name ?? '-',
                'phone' => $invoice->owner?->phone ?? '',
                'email' => $invoice->owner?->email ?? '',
            ],
            'property' => ['name' => $invoice->property?->name ?? $invoice->unit?->property?->name ?? ''],
            'unit' => ['unit_number' => $invoice->unit?->unit_number ?? ''],
            'company' => [
                'name_ar'       => $tax['company_name_ar'],
                'name_en'       => $tax['company_name_en'],
                'address_ar'    => $tax['company_address_ar'] ?? '',
                'address_en'    => $tax['company_address_en'] ?? '',
                'vat_number'    => $vatEnabled ? $tax['vat_number'] : '',
                'cr_number'     => $tax['commercial_registration'] ?? '',
                'logo'          => $associationBranding['logo'] ?? $associationBranding['icon'] ?? null,
            ],
            'tax' => [
                'vat_enabled'      => $vatEnabled,
                'vat_rate'         => (float) ($tax['vat_rate'] ?? 15),
                'zatca_enabled'    => $zatcaQr !== '',
                'zatca_phase'      => '1',
                'legal_text_ar'    => '',
                'legal_text_en'    => '',
            ],
            'qr' => ['view_url' => $qr->viewUrl($invoice, $association->id)],
            'zatca_qr' => $zatcaQr,
        ]);
    }
}
