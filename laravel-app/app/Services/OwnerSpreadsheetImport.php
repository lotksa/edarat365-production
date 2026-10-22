<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Owner;
use App\Models\Property;
use App\Scopes\AssociationScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;

/** Add-only import. A preview never creates, restores or edits an owner. */
final class OwnerSpreadsheetImport
{
    public const COLUMNS = ['اسم المالك', 'رقم الهوية', 'رقم الجوال', 'البريد الإلكتروني', 'الحالة', 'رقم العقار'];
    public const LIMIT = 1000;

    private const ALIASES = [
        'اسم المالك' => 'full_name', 'الاسم الكامل' => 'full_name', 'الاسم' => 'full_name',
        'full_name' => 'full_name', 'owner name' => 'full_name', 'name' => 'full_name',
        'رقم الهوية' => 'national_id', 'الهوية' => 'national_id', 'national_id' => 'national_id', 'national id' => 'national_id',
        'رقم الجوال' => 'phone', 'الجوال' => 'phone', 'phone' => 'phone', 'mobile' => 'phone',
        'البريد الإلكتروني' => 'email', 'البريد الالكتروني' => 'email', 'البريد' => 'email', 'email' => 'email',
        'الحالة' => 'status', 'status' => 'status',
        'رقم العقار' => 'property_number', 'property_number' => 'property_number', 'property number' => 'property_number',
    ];

    public function preview(UploadedFile $file, int $userId, ?int $propertyId = null): array
    {
        $association = app(AssociationContext::class)->id;
        abort_unless($association, 409, 'اختر الجمعية أولاً');
        if ($propertyId) Property::findOrFail($propertyId);
        $source = $this->read($file);
        $headers = $source['headers'];
        $mapping = [];
        $ignored = [];
        foreach ($headers as $index => $header) {
            $normalized = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $header)));
            if (!isset(self::ALIASES[$normalized])) { if ($normalized !== '') $ignored[] = mb_substr($header, 0, 100); continue; }
            $field = self::ALIASES[$normalized];
            if (in_array($field, $mapping, true)) $this->fail('يوجد عمود مكرر لنفس الحقل؛ احتفظ بعمود واحد لكل بيان');
            $mapping[$index] = $field;
        }
        if (!in_array('full_name', $mapping, true) || !in_array('national_id', $mapping, true)) {
            $this->fail('يجب وجود عمودي اسم المالك ورقم الهوية؛ استخدم النموذج أو عناوينه المعتمدة');
        }

        $properties = Property::pluck('id', 'property_number')->all();
        $parsed = [];
        foreach ($source['rows'] as $sourceRow) {
            $data = ['status' => 'active'];
            $errors = [];
            $warnings = [];
            foreach ($mapping as $index => $field) {
                $data[$field] = trim((string) ($sourceRow['values'][$index] ?? ''));
                if (in_array($index, $sourceRow['formulas'], true)) $errors[] = 'الملف يحتوي صيغة في أحد حقول المالك؛ استبدلها بقيمة نصية';
            }
            $data['national_id'] = $this->digits($data['national_id']);
            $phone = preg_replace('/[\s()\-]/u', '', $this->digits($data['phone'] ?? ''));
            if (preg_match('/^(?:\+966|00966|966)(5\d{8})$/', $phone, $m)) $phone = '0'.$m[1];
            if (preg_match('/^5\d{8}$/', $phone)) { $phone = '0'.$phone; $warnings[] = 'أُعيد الصفر الأول لرقم الجوال الذي حذفه Excel؛ راجع الرقم'; }
            $data['phone'] = $phone;
            $data['email'] = $data['email'] ?? '';
            $data['status'] = match (mb_strtolower($data['status'] ?: 'active')) {
                'active', 'نشط', 'نشطة' => 'active', 'inactive', 'متوقف', 'غير نشط', 'موقوف' => 'inactive', default => $data['status'],
            };
            $validation = Validator::make($data, [
                'full_name' => ['required', 'string', 'max:255', 'not_regex:/^[=+@\-]/'],
                'national_id' => ['required', 'regex:/^\d{10}$/'],
                'phone' => ['nullable', 'regex:/^05\d{8}$/'],
                'email' => ['nullable', 'email:rfc', 'max:255'],
                'status' => ['required', 'in:active,inactive'],
            ], [
                'full_name.required' => 'اسم المالك مطلوب', 'full_name.max' => 'اسم المالك أطول من 255 حرفًا',
                'full_name.not_regex' => 'اسم المالك يجب أن يكون نصًا وليس صيغة',
                'national_id.required' => 'رقم الهوية مطلوب', 'national_id.regex' => 'رقم الهوية يجب أن يكون 10 أرقام',
                'phone.regex' => 'الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05', 'email.email' => 'البريد الإلكتروني غير صحيح',
                'status.in' => 'الحالة يجب أن تكون نشط أو متوقف',
            ]);
            $errors = array_merge($errors, $validation->errors()->all());
            $targetProperty = $propertyId;
            if (!empty($data['property_number'])) {
                $targetProperty = $properties[$data['property_number']] ?? null;
                if (!$targetProperty) $errors[] = 'رقم العقار غير موجود في الجمعية الحالية';
            }
            unset($data['property_number']);
            $parsed[] = ['row' => $sourceRow['row'], 'data' => $data, 'property_id' => $targetProperty,
                'errors' => $errors, 'warnings' => $warnings];
        }
        if (!$parsed) $this->fail('الملف لا يحتوي سجلات بعد صف العناوين');
        $hashes = array_values(array_unique(array_map(fn ($row) => Owner::blindHash($row['data']['national_id']), $parsed)));
        $existing = Owner::withoutGlobalScope(AssociationScope::class)->withTrashed()
            ->whereIn('national_id_hash', $hashes)->pluck('national_id_hash')->flip()->all();
        $seen = [];
        $ready = [];
        $rows = [];
        $counts = ['total' => count($parsed), 'ready' => 0, 'duplicate' => 0, 'invalid' => 0];
        foreach ($parsed as $row) {
            $hash = Owner::blindHash($row['data']['national_id']);
            $status = $row['errors'] ? 'invalid' : ((isset($existing[$hash]) || isset($seen[$hash])) ? 'duplicate' : 'ready');
            if ($status === 'duplicate') $row['errors'][] = 'الهوية مسجلة مسبقًا أو مكررة في الملف؛ سيُحفظ الحساب الحالي كما هو';
            if ($status === 'ready') { $seen[$hash] = true; $ready[] = $row; }
            $counts[$status]++;
            $rows[] = ['row' => $row['row'], 'full_name' => $row['data']['full_name'],
                'national_id_hint' => '••••••'.substr($row['data']['national_id'], -4),
                'phone' => $row['data']['phone'], 'email' => $row['data']['email'],
                'status' => $status, 'errors' => $row['errors'], 'warnings' => $row['warnings']];
        }
        $token = $ready ? (string) Str::uuid() : null;
        $expires = now()->addMinutes(15);
        if ($token) Cache::put($this->key($userId, $token), Crypt::encryptString(json_encode([
            'user_id' => $userId, 'association_id' => $association, 'rows' => $ready, 'total' => count($rows),
        ], JSON_THROW_ON_ERROR)), $expires);
        return ['token' => $token, 'counts' => $counts, 'rows' => $rows, 'sheet' => $source['sheet'],
            'ignored_columns' => array_values(array_unique($ignored)), 'expires_at' => $expires->toIso8601String()];
    }

    public function commit(string $token, int $userId): array
    {
        $key = $this->key($userId, $token);
        return Cache::lock('owner-spreadsheet-import-commit', 120)->block(5, function () use ($key, $userId) {
            $cached = Cache::get($key);
            if (!$cached) $this->fail('انتهت صلاحية المعاينة؛ أعد اختيار الملف ومراجعته');
            $batch = json_decode(Crypt::decryptString($cached), true, 512, JSON_THROW_ON_ERROR);
            abort_unless($batch['user_id'] === $userId && $batch['association_id'] === app(AssociationContext::class)->id, 409, 'المعاينة تخص جمعية أخرى؛ أعد المراجعة داخل الجمعية الحالية');
            if (isset($batch['receipt'])) return $batch['receipt'];
            $receipt = DB::transaction(function () use ($batch) {
                $created = [];
                foreach ($batch['rows'] as $row) {
                    // Revalidate at commit time too. No upsert/restore/update of
                    // an existing identity, including another workspace's owner.
                    if (Owner::withoutGlobalScope(AssociationScope::class)->withTrashed()
                        ->where('national_id_hash', Owner::blindHash($row['data']['national_id']))->exists()) continue;
                    if ($row['property_id']) Property::findOrFail($row['property_id']);
                    $owner = Owner::create([
                        ...$row['data'], 'phone' => $row['data']['phone'] ?: null, 'email' => $row['data']['email'] ?: null,
                    ]);
                    if ($row['property_id']) $owner->properties()->syncWithoutDetaching([$row['property_id']]);
                    ActivityLog::record('owner', $owner->id, 'imported', 'تمت إضافة المالك من ملف بعد المعاينة', null,
                        ['account_number' => $owner->account_number]);
                    $created[] = ['id' => $owner->id, 'account_number' => $owner->account_number, 'full_name' => $owner->full_name];
                }
                return ['message' => 'تمت إضافة '.count($created).' مالك مع الحفاظ على الحسابات السابقة',
                    'created' => count($created), 'skipped' => $batch['total'] - count($created), 'records' => $created];
            });
            Cache::put($key, Crypt::encryptString(json_encode([
                'user_id' => $userId, 'association_id' => $batch['association_id'], 'receipt' => $receipt,
            ], JSON_THROW_ON_ERROR)), now()->addMinutes(15));
            return $receipt;
        });
    }

    private function key(int $userId, string $token): string { return 'owner-spreadsheet:'.$userId.':'.$token; }
    private function fail(string $message): never { throw ValidationException::withMessages(['file' => $message]); }
    private function digits(string $value): string { return strtr($value, array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789'))); }

    private function read(UploadedFile $file): array
    {
        $rows = [];
        $sheetName = null;
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'xlsx') {
            $zip = new \ZipArchive();
            if ($zip->open($file->getRealPath()) !== true) $this->fail('ملف Excel تالف أو غير صالح');
            try {
                $size = 0;
                if ($zip->numFiles > 1000) $this->fail('ملف Excel معقد جدًا؛ ارفع ورقة البيانات فقط');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->statIndex($i);
                    $size += $entry['size'];
                    if ($size > 40 * 1024 * 1024 || preg_match('#(^[/\\\\]|\.\.[/\\\\])#', $entry['name'])) $this->fail('ملف Excel يتجاوز حدود المعالجة المسموحة');
                }
            } finally { $zip->close(); }
            $reader = new Reader();
            try {
                $reader->open($file->getRealPath());
                foreach ($reader->getSheetIterator() as $sheet) {
                    if (!$sheet->isVisible()) continue;
                    $sheetName = $sheet->getName();
                    foreach ($sheet->getRowIterator() as $number => $row) {
                        $values = [];
                        $formulas = [];
                        foreach ($row->cells as $index => $cell) {
                            $raw = $cell->getValue();
                            $values[$index] = is_scalar($raw) ? (string) $raw : '';
                            if ($cell instanceof FormulaCell) $formulas[] = $index;
                        }
                        $this->append($rows, (int) $number, $values, $formulas);
                    }
                    break; // First visible worksheet only, explicitly shown in preview.
                }
            } catch (ValidationException $e) { throw $e;
            } catch (\Throwable $e) { $this->fail('تعذرت قراءة Excel؛ استخدم ملف XLSX غير محمي بكلمة مرور');
            } finally { $reader->close(); }
        } else {
            $handle = fopen($file->getRealPath(), 'r');
            if (!$handle) $this->fail('تعذرت قراءة الملف');
            try {
                $offset = fread($handle, 3) === "\xEF\xBB\xBF" ? 3 : 0;
                fseek($handle, $offset);
                $first = fgets($handle);
                if ($first === false) $this->fail('الملف فارغ');
                $delimiter = ',';
                foreach ([';', "\t"] as $candidate) if (count(str_getcsv($first, $candidate, '"', '')) > count(str_getcsv($first, $delimiter, '"', ''))) $delimiter = $candidate;
                fseek($handle, $offset);
                $number = 0;
                while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                    $this->append($rows, ++$number, array_map(fn ($value) => (string) $value, $values), []);
                }
            } finally { fclose($handle); }
        }
        if (!$rows) $this->fail('الملف فارغ');
        $header = array_shift($rows);
        return ['headers' => $header['values'], 'rows' => $rows, 'sheet' => $sheetName];
    }

    private function append(array &$rows, int $number, array $values, array $formulas): void
    {
        if (!array_filter($values, fn ($value) => trim($value) !== '')) return;
        if (count($rows) > self::LIMIT) $this->fail('الحد الأقصى 1000 مالك لكل ملف؛ قسّم القائمة إلى ملفات أصغر');
        if (count($values) > 50) $this->fail('الحد الأقصى 50 عمودًا في الملف');
        foreach ($values as $value) if (mb_strlen($value) > 2000) $this->fail('إحدى الخلايا أطول من الحد المسموح');
        $rows[] = ['row' => $number, 'values' => $values, 'formulas' => $formulas];
    }
}
