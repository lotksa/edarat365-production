<?php

namespace App\Services;

use App\Models\Invoice;
use Carbon\CarbonImmutable;

final class InvoiceQr
{
    public function viewUrl(Invoice $invoice, int $associationId): string
    {
        $body = '1:'.$associationId.':'.$invoice->id;
        $signature = hash_hmac('sha256', $body, $this->signingKey(), true);
        $token = rtrim(strtr(base64_encode($body."\0".$signature), '+/', '-_'), '=');

        return url('/invoice-summary.html').'#'.$token;
    }

    public function verifyToken(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{45,120}$/D', $token)) return null;
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || ($separator = strpos($raw, "\0")) === false) return null;
        if (rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $token) return null;
        $body = substr($raw, 0, $separator);
        $signature = substr($raw, $separator + 1);
        if (strlen($signature) !== 32 || ! hash_equals(hash_hmac('sha256', $body, $this->signingKey(), true), $signature)) return null;
        if (! preg_match('/^1:([1-9][0-9]{0,17}):([1-9][0-9]{0,17})$/D', $body, $ids)) return null;

        return ['association_id' => (int) $ids[1], 'invoice_id' => (int) $ids[2]];
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') throw new \LogicException('Invoice summary signing requires the application key.');

        return hash_hmac('sha256', 'edarat365.invoice-summary.v1', $key, true);
    }

    /** ZATCA TLV data fields 1–5. Phase-two stamps require a real ZATCA integration. */
    public function taxPayload(Invoice $invoice, array $tax): string
    {
        if (! $tax['vat_enabled'] || ! preg_match('/^3[0-9]{13}3$/', $tax['vat_number'])) return '';
        if ($invoice->status === 'draft') return '';
        $issuedAt = $invoice->issued_at ?? $invoice->created_at ?? $invoice->issue_date;
        if (! $issuedAt) return '';
        $fields = [
            1 => $tax['company_name_ar'],
            2 => $tax['vat_number'],
            3 => CarbonImmutable::parse($issuedAt)->utc()->format('Y-m-d\TH:i:s\Z'),
            4 => number_format((float) $invoice->total_amount, 2, '.', ''),
            5 => number_format((float) $invoice->tax_amount, 2, '.', ''),
        ];
        $bytes = '';
        foreach ($fields as $tag => $value) {
            $length = strlen($value); // UTF-8 byte length, never character count.
            if ($length === 0 || $length > 255) return '';
            $bytes .= chr($tag).chr($length).$value;
        }

        return base64_encode($bytes);
    }
}
