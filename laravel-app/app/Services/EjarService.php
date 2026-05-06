<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EjarService
{
    public const STANDARD_CLAUSES = [
        [
            'key'     => 'landlord_obligations',
            'name_ar' => 'التزامات المؤجر',
            'name_en' => 'Landlord Obligations',
            'text_ar' => 'يلتزم المؤجر بتسليم العين المؤجرة بحالة صالحة للاستخدام المتفق عليه، وإجراء الصيانة الهيكلية اللازمة، وعدم التعرض للمستأجر في انتفاعه بالعين المؤجرة طوال مدة العقد.',
        ],
        [
            'key'     => 'tenant_obligations',
            'name_ar' => 'التزامات المستأجر',
            'name_en' => 'Tenant Obligations',
            'text_ar' => 'يلتزم المستأجر بدفع الأجرة في المواعيد المتفق عليها، والمحافظة على العين المؤجرة واستعمالها فيما أعدت له، وعدم إحداث تغييرات فيها دون موافقة المؤجر الكتابية.',
        ],
        [
            'key'     => 'eviction_conditions',
            'name_ar' => 'شروط الإخلاء',
            'name_en' => 'Eviction Conditions',
            'text_ar' => 'يحق للمؤجر طلب إخلاء العين المؤجرة في حالات: عدم سداد الأجرة خلال 15 يوماً من الإشعار، أو استخدام العين في غرض غير مشروع، أو إلحاق ضرر جسيم بالعين المؤجرة.',
        ],
        [
            'key'     => 'maintenance_terms',
            'name_ar' => 'شروط الصيانة',
            'name_en' => 'Maintenance Terms',
            'text_ar' => 'يتحمل المؤجر الصيانة الهيكلية والإنشائية، ويتحمل المستأجر الصيانة التشغيلية والأعطال الناتجة عن الاستخدام العادي. تُحدد مسؤولية الصيانة حسب طبيعة العطل.',
        ],
        [
            'key'     => 'utilities_services',
            'name_ar' => 'المرافق والخدمات',
            'name_en' => 'Utilities & Services',
            'text_ar' => 'يتحمل المستأجر تكاليف استهلاك الكهرباء والمياه والغاز وخدمات الاتصالات خلال فترة العقد، ما لم يُتفق على خلاف ذلك كتابياً.',
        ],
        [
            'key'     => 'insurance',
            'name_ar' => 'التأمين',
            'name_en' => 'Insurance',
            'text_ar' => 'يلتزم المستأجر بالتأمين على محتويات العين المؤجرة ضد أخطار الحريق والسرقة، ويلتزم المؤجر بالتأمين على المبنى ضد الأخطار الهيكلية.',
        ],
        [
            'key'     => 'early_termination',
            'name_ar' => 'شروط الإنهاء المبكر',
            'name_en' => 'Early Termination',
            'text_ar' => 'يجوز لأي من الطرفين إنهاء العقد قبل انتهاء مدته بشرط إشعار الطرف الآخر كتابياً قبل 60 يوماً على الأقل، مع التزام الطرف المنهي بدفع تعويض يعادل أجرة شهرين.',
        ],
        [
            'key'     => 'dispute_resolution',
            'name_ar' => 'حل النزاعات',
            'name_en' => 'Dispute Resolution',
            'text_ar' => 'في حالة نشوء أي خلاف بين الطرفين، يتم حله ودياً أولاً عبر منصة إيجار. وفي حال تعذر ذلك، يُحال النزاع إلى الجهات القضائية المختصة بالمملكة العربية السعودية.',
        ],
    ];

    private function getSettings(): array
    {
        $integrations = Setting::getByKey('integrations', []);
        return $integrations['ejar'] ?? [];
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->getSettings()['enabled'] ?? false);
    }

    public function createContract(Contract $contract): ?array
    {
        $settings = $this->getSettings();
        if (!($settings['enabled'] ?? false) || empty($settings['api_url'])) {
            return null;
        }

        $contract->load(['tenant', 'unit.property.association', 'owner']);

        $payload = [
            'entity_id'      => $settings['entity_id'] ?? '',
            'contract_type'  => $contract->contract_type,
            'landlord'       => [
                'name'        => $contract->owner?->full_name,
                'national_id' => $contract->owner?->national_id,
                'phone'       => $contract->owner?->phone,
            ],
            'tenant'         => [
                'name'        => $contract->tenant?->full_name ?? $contract->tenant_name,
                'national_id' => $contract->tenant?->national_id,
                'phone'       => $contract->tenant?->phone,
            ],
            'unit'           => [
                'number'      => $contract->unit?->unit_number,
                'type'        => $contract->unit?->unit_type,
                'area'        => $contract->unit?->area,
                'property'    => $contract->unit?->property?->name,
            ],
            'rental_amount'  => $contract->rental_amount,
            'payment_type'   => $contract->payment_type,
            'start_date'     => $contract->start_date?->format('Y-m-d'),
            'end_date'       => $contract->end_date?->format('Y-m-d'),
            'clauses'        => $contract->contract_clauses,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . ($settings['api_key'] ?? ''),
                'X-Api-Secret'  => $settings['api_secret'] ?? '',
                'Accept'        => 'application/json',
            ])->timeout(30)->post(rtrim($settings['api_url'], '/') . '/contracts', $payload);

            if ($response->successful()) {
                $body = $response->json();
                $contract->update([
                    'ejar_reference_id' => $body['reference_id'] ?? $body['id'] ?? null,
                    'ejar_status'       => $body['status'] ?? 'submitted',
                    'ejar_synced_at'    => now(),
                ]);
                return $body;
            }

            Log::warning('Ejar API returned non-success', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('Ejar API call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getContractStatus(string $ejarRef): ?array
    {
        $settings = $this->getSettings();
        if (!($settings['enabled'] ?? false) || empty($settings['api_url'])) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . ($settings['api_key'] ?? ''),
                'X-Api-Secret'  => $settings['api_secret'] ?? '',
                'Accept'        => 'application/json',
            ])->timeout(15)->get(rtrim($settings['api_url'], '/') . '/contracts/' . $ejarRef);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::error('Ejar status check failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
