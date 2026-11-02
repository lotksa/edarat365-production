<?php

namespace App\Services;

use App\Models\Association;
use App\Models\Setting;

final class AssociationInvoiceSettings
{
    public function key(int $associationId): string
    {
        return 'association_invoice_settings_'.$associationId;
    }

    public function forAssociation(Association $association): array
    {
        $saved = Setting::getByKey($this->key($association->id));

        return [
            // A different association's/global VAT registration never enables VAT here.
            'vat_enabled' => filter_var($saved['vat_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'vat_rate' => (float) ($saved['vat_rate'] ?? 15),
            'vat_number' => (string) ($saved['vat_number'] ?? ''),
            'commercial_registration' => (string) ($saved['commercial_registration'] ?? ''),
            'company_name_ar' => (string) ($saved['company_name_ar'] ?? $association->name),
            'company_name_en' => (string) ($saved['company_name_en'] ?? $association->name_en ?? ''),
            'company_address_ar' => (string) ($saved['company_address_ar'] ?? $association->address ?? ''),
            'company_address_en' => (string) ($saved['company_address_en'] ?? ''),
        ];
    }
}
