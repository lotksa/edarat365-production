<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Association;
use App\Models\Setting;
use App\Services\AssociationInvoiceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AssociationInvoiceSettingsController extends Controller
{
    public function show(int $id, AssociationInvoiceSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->forAssociation(Association::findOrFail($id))]);
    }

    public function update(Request $request, int $id, AssociationInvoiceSettings $settings): JsonResponse
    {
        $association = Association::findOrFail($id);
        $enabled = $request->boolean('vat_enabled');
        $data = $request->validate([
            'vat_enabled' => ['required', 'boolean'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'vat_number' => [Rule::requiredIf($enabled), 'nullable', 'regex:/^3[0-9]{13}3$/'],
            'commercial_registration' => ['nullable', 'string', 'max:30'],
            'company_name_ar' => ['required', 'string', 'max:120', function ($attribute, $value, $fail) {
                if (strlen($value) > 255) $fail('اسم المورد يتجاوز الحد المسموح لرمز الفاتورة.');
            }],
            'company_name_en' => ['nullable', 'string', 'max:120'],
            'company_address_ar' => ['nullable', 'string', 'max:500'],
            'company_address_en' => ['nullable', 'string', 'max:500'],
        ]);
        $before = $settings->forAssociation($association);
        $data['vat_enabled'] = $enabled;
        Setting::setByKey($settings->key($association->id), $data);
        ActivityLog::record('association', $association->id, 'updated', 'تم تحديث إعدادات فواتير الجمعية', $before, $data);

        return response()->json(['data' => $settings->forAssociation($association)]);
    }
}
