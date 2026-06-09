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
            'account_number' => 'رقم الحساب',
            'full_name' => 'الاسم الكامل',
            'national_id' => 'رقم الهوية',
            'phone' => 'رقم الجوال',
            'email' => 'البريد الإلكتروني',
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

    private const OWNER_TEMPLATE_COLUMNS = [
        'full_name'   => 'اسم المالك',
        'national_id' => 'رقم الهوية',
        'phone'       => 'رقم الجوال',
        'email'       => 'البريد الإلكتروني',
        'status'      => 'الحالة',
    ];

    private const OWNER_IMPORT_ALIASES = [
        'اسم المالك' => 'full_name',
        'الاسم الكامل' => 'full_name',
        'اسم المالك / Owner Name' => 'full_name',
        'Owner Name' => 'full_name',
        'full_name' => 'full_name',

        'رقم الهوية' => 'national_id',
        'رقم الهوية / National ID' => 'national_id',
        'National ID' => 'national_id',
        'national_id' => 'national_id',

        'رقم الجوال' => 'phone',
        'رقم الجوال / Phone' => 'phone',
        'Phone' => 'phone',
        'phone' => 'phone',

        'البريد الإلكتروني' => 'email',
        'البريد الإلكتروني / Email' => 'email',
        'Email' => 'email',
        'email' => 'email',

        'الحالة' => 'status',
        'الحالة / Status' => 'status',
        'Status' => 'status',
        'status' => 'status',
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

        $cols = $module === 'owners' ? self::OWNER_TEMPLATE_COLUMNS : self::COLUMN_MAP[$module];
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

        if ($module === 'owners') {
            return $this->importOwners($request);
        }

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], [
            'file.required' => 'الملف مطلوب',
            'file.mimes'    => 'يجب أن يكون الملف بصيغة CSV',
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

    private function importOwners(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], [
            'file.required' => 'الملف مطلوب',
            'file.mimes'    => 'يجب أن يكون ملف الملاك بصيغة CSV',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('file');
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

        $fieldByIndex = [];
        foreach ($csvHeaders as $idx => $header) {
            $normalized = $this->cleanHeader((string) $header);
            if (isset(self::OWNER_IMPORT_ALIASES[$normalized])) {
                $fieldByIndex[$idx] = self::OWNER_IMPORT_ALIASES[$normalized];
            }
        }

        if (!in_array('full_name', $fieldByIndex, true) || !in_array('national_id', $fieldByIndex, true)) {
            fclose($handle);
            return response()->json([
                'message' => 'نموذج الملاك غير صحيح: يجب وجود أعمدة اسم المالك ورقم الهوية',
            ], 422);
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $lineNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if ($this->isEmptyCsvRow($row)) {
                continue;
            }

            $data = [
                'status' => 'active',
            ];

            foreach ($fieldByIndex as $idx => $field) {
                $data[$field] = trim((string) ($row[$idx] ?? ''));
            }

            $rowErrors = $this->validateOwnerImportRow($data);
            if (!empty($rowErrors)) {
                foreach ($rowErrors as $error) {
                    $errors[] = "سطر {$lineNum}: {$error}";
                }
                $skipped++;
                if (count($errors) >= 10) {
                    break;
                }
                continue;
            }

            $nationalId = $data['national_id'];
            if (Owner::where('national_id_hash', Owner::blindHash($nationalId))->exists()) {
                $errors[] = "سطر {$lineNum}: رقم الهوية موجود مسبقاً";
                $skipped++;
                if (count($errors) >= 10) {
                    break;
                }
                continue;
            }

            try {
                Owner::create([
                    'full_name'   => $data['full_name'],
                    'national_id' => $nationalId,
                    'phone'       => ($data['phone'] ?? '') ?: null,
                    'email'       => ($data['email'] ?? '') ?: null,
                    'status'      => $this->normalizeOwnerStatus($data['status'] ?? null),
                ]);
                $created++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Owner import row failed', [
                    'line' => $lineNum,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = "سطر {$lineNum}: تعذّر استيراد بيانات المالك";
                $skipped++;
                if (count($errors) >= 10) {
                    break;
                }
            }
        }

        fclose($handle);

        return response()->json([
            'message' => "تم استيراد {$created} مالك بنجاح",
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    private function cleanHeader(string $header): string
    {
        return trim(str_replace("\xEF\xBB\xBF", '', $header));
    }

    private function isEmptyCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function validateOwnerImportRow(array $data): array
    {
        $errors = [];

        if (($data['full_name'] ?? '') === '') {
            $errors[] = 'اسم المالك مطلوب';
        }

        if (($data['national_id'] ?? '') === '') {
            $errors[] = 'رقم الهوية مطلوب';
        } elseif (!preg_match('/^\d{10}$/', (string) $data['national_id'])) {
            $errors[] = 'رقم الهوية يجب أن يكون 10 أرقام';
        }

        if (($data['phone'] ?? '') !== '' && !preg_match('/^05\d{8}$/', (string) $data['phone'])) {
            $errors[] = 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05';
        }

        if (($data['email'] ?? '') !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'البريد الإلكتروني غير صحيح';
        }

        if (($data['status'] ?? '') !== '' && $this->normalizeOwnerStatus($data['status']) === null) {
            $errors[] = 'الحالة يجب أن تكون نشط أو متوقف';
        }

        return $errors;
    }

    private function normalizeOwnerStatus(?string $status): ?string
    {
        $status = trim((string) $status);
        if ($status === '') {
            return 'active';
        }

        return match (mb_strtolower($status)) {
            'active', 'نشط' => 'active',
            'inactive', 'متوقف', 'غير نشط' => 'inactive',
            default => null,
        };
    }
}
