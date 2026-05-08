<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Owner, Unit, Association, Property, Invoice, Voucher, LegalCase, ParkingSpot, Vehicle, Contract, Meeting, Vote, LegalRepresentative};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportImportController extends Controller
{
    private const MODULE_MAP = [
        'owners'        => ['model' => Owner::class,      'with' => []],
        'units'         => ['model' => Unit::class,        'with' => ['property', 'association']],
        'associations'  => ['model' => Association::class,  'with' => []],
        'properties'    => ['model' => Property::class,     'with' => ['association']],
        'invoices'      => ['model' => Invoice::class,      'with' => ['owner']],
        'vouchers'      => ['model' => Voucher::class,      'with' => ['owner']],
        'legal-cases'   => ['model' => LegalCase::class,    'with' => ['owner', 'property']],
        'parking-spots' => ['model' => ParkingSpot::class,  'with' => ['association']],
        'vehicles'      => ['model' => Vehicle::class,      'with' => []],
        'contracts'     => ['model' => Contract::class,     'with' => ['owner', 'property', 'unit']],
        'meetings'      => ['model' => Meeting::class,      'with' => ['association']],
        'votes'         => ['model' => Vote::class,         'with' => ['association']],
        'legal-representatives' => ['model' => LegalRepresentative::class, 'with' => ['user']],
    ];

    private const COLUMN_MAP = [
        'owners' => [
            'id' => 'ID',
            'full_name' => 'الاسم الكامل',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الجوال',
            'national_id' => 'رقم الهوية',
            'status' => 'الحالة',
            'created_at' => 'تاريخ الإنشاء',
        ],
        'units' => [
            'id' => 'ID',
            'unit_number' => 'رقم الوحدة',
            'unit_code' => 'كود الوحدة',
            'type' => 'النوع',
            'area' => 'المساحة',
            'status' => 'الحالة',
            'property.name' => 'العقار',
            'association.name' => 'الجمعية',
            'created_at' => 'تاريخ الإنشاء',
        ],
        'associations' => [
            'id' => 'ID',
            'name' => 'اسم الجمعية',
            'registration_number' => 'رقم التسجيل',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الجوال',
            'status' => 'الحالة',
            'created_at' => 'تاريخ الإنشاء',
        ],
        'properties' => [
            'id' => 'ID',
            'name' => 'اسم العقار',
            'property_type' => 'نوع العقار',
            'city' => 'المدينة',
            'district' => 'الحي',
            'status' => 'الحالة',
            'association.name' => 'الجمعية',
            'created_at' => 'تاريخ الإنشاء',
        ],
        'invoices' => [
            'id' => 'ID',
            'invoice_number' => 'رقم الفاتورة',
            'owner.full_name' => 'اسم المالك',
            'amount' => 'المبلغ',
            'vat_rate' => 'نسبة الضريبة',
            'tax_amount' => 'مبلغ الضريبة',
            'discount_amount' => 'الخصم',
            'total_amount' => 'المبلغ المستحق',
            'issue_date' => 'تاريخ الفاتورة',
            'payment_date' => 'تاريخ الدفع',
            'status' => 'الحالة',
        ],
        'vouchers' => [
            'id' => 'ID',
            'voucher_number' => 'رقم السند',
            'type' => 'نوع السند',
            'owner.full_name' => 'المالك',
            'payment_method' => 'طريقة الدفع',
            'amount' => 'المبلغ',
            'payment_date' => 'تاريخ الدفع',
            'description' => 'الوصف',
        ],
        'legal-cases' => [
            'id' => 'ID',
            'case_number' => 'رقم القضية',
            'case_type' => 'نوع القضية',
            'court_type' => 'المحكمة',
            'owner.full_name' => 'المالك',
            'case_date' => 'تاريخ القضية',
            'status' => 'الحالة',
        ],
        'parking-spots' => [
            'id' => 'ID',
            'spot_number' => 'رقم الموقف',
            'spot_type' => 'نوع الموقف',
            'association.name' => 'الجمعية',
            'property_name' => 'العقار',
            'status' => 'الحالة',
        ],
        'vehicles' => [
            'id' => 'ID',
            'plate_number' => 'رقم اللوحة',
            'vehicle_type' => 'نوع المركبة',
            'brand' => 'الماركة',
            'model' => 'الموديل',
            'color' => 'اللون',
            'year' => 'السنة',
            'status' => 'الحالة',
        ],
        'contracts' => [
            'id' => 'ID',
            'contract_number' => 'رقم العقد',
            'contract_nature' => 'طبيعة العقد',
            'owner.full_name' => 'المالك',
            'property.name' => 'العقار',
            'start_date' => 'تاريخ البداية',
            'end_date' => 'تاريخ النهاية',
            'status' => 'الحالة',
        ],
        'meetings' => [
            'id' => 'ID',
            'title' => 'العنوان',
            'meeting_type' => 'نوع الاجتماع',
            'association.name' => 'الجمعية',
            'meeting_date' => 'التاريخ',
            'status' => 'الحالة',
        ],
        'votes' => [
            'id' => 'ID',
            'title' => 'العنوان',
            'association.name' => 'الجمعية',
            'start_date' => 'تاريخ البداية',
            'end_date' => 'تاريخ النهاية',
            'status' => 'الحالة',
        ],
        'legal-representatives' => [
            'id' => 'ID',
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الجوال',
            'specialty' => 'التخصص',
            'license_number' => 'رقم الرخصة',
            'firm_name' => 'اسم المكتب',
            'user.full_name' => 'المستخدم المرتبط',
            'status' => 'الحالة',
        ],
    ];

    public function export(string $module, Request $request): StreamedResponse
    {
        if (!isset(self::MODULE_MAP[$module])) {
            abort(404, 'Module not found');
        }

        $cfg = self::MODULE_MAP[$module];
        $cols = self::COLUMN_MAP[$module] ?? [];
        $modelClass = $cfg['model'];

        $query = $modelClass::query();
        if (!empty($cfg['with'])) {
            $query->with($cfg['with']);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search, $cols) {
                foreach (array_keys($cols) as $col) {
                    if (!str_contains($col, '.') && $col !== 'id' && $col !== 'created_at') {
                        $q->orWhere($col, 'like', "%{$search}%");
                    }
                }
            });
        }

        $records = $query->latest('id')->get();

        $headers = array_values($cols);
        $keys = array_keys($cols);

        $filename = "{$module}_" . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($records, $headers, $keys) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, $headers);

            foreach ($records as $row) {
                $line = [];
                foreach ($keys as $key) {
                    $line[] = data_get($row, $key, '') ?? '';
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function template(string $module): StreamedResponse
    {
        if (!isset(self::COLUMN_MAP[$module])) {
            abort(404, 'Module not found');
        }

        $cols = self::COLUMN_MAP[$module];
        $headers = array_values($cols);
        $filename = "{$module}_template.csv";

        return response()->streamDownload(function () use ($headers) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, $headers);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(string $module, Request $request): JsonResponse
    {
        if (!isset(self::MODULE_MAP[$module])) {
            return response()->json(['message' => 'Module not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ], [
            'file.required' => 'الملف مطلوب',
            'file.mimes'    => 'يجب أن يكون الملف بصيغة CSV أو Excel',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('file');
        $cfg = self::MODULE_MAP[$module];
        $modelClass = $cfg['model'];
        $cols = self::COLUMN_MAP[$module] ?? [];
        $headerMap = array_flip(array_values($cols));
        $keyMap = array_values(array_keys($cols));

        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return response()->json(['message' => 'فشل في قراءة الملف'], 422);
        }

        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            fseek($handle, 0);
        }

        $csvHeaders = fgetcsv($handle);
        if (!$csvHeaders) {
            fclose($handle);
            return response()->json(['message' => 'الملف فارغ أو غير صالح'], 422);
        }

        $created = 0;
        $errors = [];
        $lineNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) < 2) continue;

            $data = [];
            foreach ($csvHeaders as $idx => $header) {
                $header = trim($header);
                if (isset($headerMap[$header])) {
                    $colIdx = $headerMap[$header];
                    $fieldKey = $keyMap[$colIdx] ?? null;
                    if ($fieldKey && !str_contains($fieldKey, '.') && $fieldKey !== 'id' && $fieldKey !== 'created_at') {
                        $data[$fieldKey] = $row[$idx] ?? '';
                    }
                }
            }

            if (empty($data)) continue;

            try {
                $modelClass::create($data);
                $created++;
            } catch (\Throwable $e) {
                // SECURITY: never leak DB column names / SQL state to clients.
                // Log details server-side; surface a generic per-row error to user.
                \Illuminate\Support\Facades\Log::warning('Import row failed', [
                    'line'  => $lineNum,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = "سطر {$lineNum}: تعذّر استيراد البيانات";
                if (count($errors) > 10) break;
            }
        }

        fclose($handle);

        return response()->json([
            'message' => "تم استيراد {$created} سجل بنجاح",
            'created' => $created,
            'errors'  => $errors,
        ]);
    }
}
