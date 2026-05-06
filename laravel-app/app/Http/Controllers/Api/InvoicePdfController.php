<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoicePdfController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with(['association', 'property', 'owner', 'unit', 'tenant'])->findOrFail($id);

        $invoiceSettings = Setting::getByKey('invoice_settings', []);
        $tax = $invoiceSettings['tax'] ?? [];
        $generalSettings = Setting::getByKey('general', []);
        $brandSettings = Setting::getByKey('brand_identity', []);

        $sellerName = $tax['company_name_ar'] ?? ($generalSettings['site_name_ar'] ?? 'إدارات 365');
        $vatNumber  = $tax['vat_number'] ?? '';
        $invoiceDate = $invoice->issue_date ?? $invoice->created_at?->toDateString() ?? now()->toDateString();
        $totalWithVat = (float) ($invoice->total_amount ?? 0);
        $vatAmount = (float) ($invoice->tax_amount ?? 0);

        $zatcaQr = $this->generateZatcaQr($sellerName, $vatNumber, $invoiceDate, $totalWithVat, $vatAmount);

        $lineItems = is_array($invoice->line_items) ? $invoice->line_items : [];

        return response()->json([
            'invoice' => [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'issue_date'     => $invoiceDate,
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
            'owner' => [
                'name'        => $invoice->owner?->full_name ?? '-',
                'national_id' => $invoice->owner?->national_id ?? '',
                'phone'       => $invoice->owner?->phone ?? '',
                'email'       => $invoice->owner?->email ?? '',
            ],
            'company' => [
                'name_ar'       => $tax['company_name_ar'] ?? ($generalSettings['site_name_ar'] ?? 'إدارات 365'),
                'name_en'       => $tax['company_name_en'] ?? ($generalSettings['site_name_en'] ?? 'Edarat365'),
                'address_ar'    => $tax['company_address_ar'] ?? '',
                'address_en'    => $tax['company_address_en'] ?? '',
                'vat_number'    => $vatNumber,
                'cr_number'     => $tax['commercial_registration'] ?? '',
                'logo'          => $brandSettings['sidebar_logo_light'] ?? ($generalSettings['site_logo'] ?? '/brand/logo.png'),
            ],
            'tax' => [
                'vat_enabled'      => $tax['vat_enabled'] ?? true,
                'vat_rate'         => (float) ($tax['vat_rate'] ?? 15),
                'zatca_enabled'    => $tax['zatca_enabled'] ?? true,
                'zatca_phase'      => $tax['zatca_phase'] ?? '2',
                'legal_text_ar'    => $tax['zatca_legal_text_ar'] ?? '',
                'legal_text_en'    => $tax['zatca_legal_text_en'] ?? '',
            ],
            'zatca_qr' => $zatcaQr,
        ]);
    }

    /**
     * ZATCA Phase 2 TLV QR code (base64-encoded).
     * Tags: 1=Seller, 2=VAT Number, 3=Timestamp, 4=Total, 5=VAT Amount
     */
    private function generateZatcaQr(string $seller, string $vatNumber, string $date, float $total, float $vat): string
    {
        $timestamp = $date . 'T00:00:00Z';

        $tlv  = $this->tlvEncode(1, $seller);
        $tlv .= $this->tlvEncode(2, $vatNumber);
        $tlv .= $this->tlvEncode(3, $timestamp);
        $tlv .= $this->tlvEncode(4, number_format($total, 2, '.', ''));
        $tlv .= $this->tlvEncode(5, number_format($vat, 2, '.', ''));

        return base64_encode($tlv);
    }

    private function tlvEncode(int $tag, string $value): string
    {
        $valueBytes = $value;
        $length = strlen($valueBytes);
        return chr($tag) . chr($length) . $valueBytes;
    }
}
