<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Owner;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwnerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Owner::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('account_number', 'like', "%{$search}%")
                  ->orWhere('national_id', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = (int) $request->query('per_page', 15);
        $records = $query->latest('id')->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $total = Owner::count();
        $active = Owner::where('status', 'active')->count();

        return response()->json([
            'total'    => $total,
            'active'   => $active,
            'inactive' => $total - $active,
        ]);
    }

    private function addressRules(): array
    {
        return [
            'has_national_address' => ['nullable', 'boolean'],
            'address_type'         => ['nullable', 'string', 'in:full,short'],
            'address_short_code'   => ['nullable', 'string', 'max:100'],
            'address_region'       => ['nullable', 'string', 'max:255'],
            'address_city'         => ['nullable', 'string', 'max:255'],
            'address_district'     => ['nullable', 'string', 'max:255'],
            'address_street'       => ['nullable', 'string', 'max:255'],
            'address_building_no'  => ['nullable', 'string', 'max:50'],
            'address_additional_no'=> ['nullable', 'string', 'max:50'],
            'address_postal_code'  => ['nullable', 'string', 'max:10'],
            'address_unit_no'      => ['nullable', 'string', 'max:50'],
        ];
    }

    private function handleAvatar(Request $request, ?Owner $existing = null): ?string
    {
        if ($request->hasFile('avatar')) {
            if ($existing && $existing->avatar) {
                Storage::disk('public')->delete($existing->avatar);
            }
            return $request->file('avatar')->store('owners/avatars', 'public');
        }
        return null;
    }

    public function store(Request $request): JsonResponse
    {
        $nationalId = $request->input('national_id');

        $trashed = Owner::onlyTrashed()->where('national_id_hash', Owner::blindHash((string) $nationalId))->first();
        if ($trashed) {
            $oldAccountId = $trashed->id;
            $trashed->restore();
            $updateData = [
                'full_name' => $request->input('full_name', $trashed->full_name),
                'phone'     => $request->input('phone', $trashed->phone),
                'email'     => $request->input('email', $trashed->email),
                'status'    => 'active',
                'previous_account_id' => $oldAccountId,
            ];
            $avatarPath = $this->handleAvatar($request, $trashed);
            if ($avatarPath) $updateData['avatar'] = $avatarPath;
            $trashed->update($updateData);

            ActivityLog::record('owner', $trashed->id, 'restored',
                'تم إعادة تفعيل حساب المالك (كان محذوفاً مسبقاً)',
                null, ['restored_from' => $oldAccountId]
            );

            return response()->json([
                'message'  => 'تم إعادة تفعيل حساب المالك بنجاح (حساب سابق)',
                'data'     => $trashed->fresh(),
                'restored' => true,
            ], 200);
        }

        $ownerSettings = Setting::getByKey('owner_settings', []);
        $addressRequired = $ownerSettings['national_address_required'] ?? false;

        $rules = array_merge([
            'full_name'   => ['required', 'string', 'max:255'],
            'national_id' => ['required', 'string', 'size:10', 'regex:/^\d{10}$/', new \App\Rules\UniqueEncrypted('owners', 'national_id_hash')],
            'phone'       => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/'],
            'email'       => ['nullable', 'email', 'max:255'],
            'avatar'      => ['nullable', 'image', 'max:2048'],
        ], $this->addressRules());

        if ($addressRequired) {
            $rules['has_national_address'] = ['required', 'accepted'];
        }

        $data = $request->validate($rules, [
            'national_id.size'  => 'رقم الهوية يجب أن يكون 10 أرقام',
            'national_id.regex' => 'رقم الهوية يجب أن يكون 10 أرقام فقط',
            'phone.size'        => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex'       => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'has_national_address.accepted' => 'العنوان الوطني إلزامي حسب إعدادات النظام',
            'avatar.image'      => 'الصورة يجب أن تكون بصيغة صورة صحيحة',
            'avatar.max'        => 'حجم الصورة يجب ألا يتجاوز 2 ميجابايت',
        ]);

        unset($data['avatar']);
        $avatarPath = $this->handleAvatar($request);
        if ($avatarPath) $data['avatar'] = $avatarPath;

        $data['status'] = 'active';
        if (!($data['has_national_address'] ?? false)) {
            $data = array_merge($data, [
                'address_type' => null, 'address_short_code' => null,
                'address_region' => null, 'address_city' => null,
                'address_district' => null, 'address_street' => null,
                'address_building_no' => null, 'address_additional_no' => null,
                'address_postal_code' => null, 'address_unit_no' => null,
            ]);
        }
        $owner = Owner::create($data);

        ActivityLog::record('owner', $owner->id, 'created', 'تم إنشاء حساب مالك جديد');

        return response()->json([
            'message' => 'تم إضافة المالك بنجاح',
            'data'    => $owner,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $owner = Owner::with([
            'units.property', 'units.images',
            'invoices', 'contracts', 'maintenanceRequests', 'bookings',
            'vehicles.parkingSpot', 'transactions', 'vouchers',
            'voteResponses.vote',
        ])->findOrFail($id);

        $data = $owner->toArray();
        $data['activity_logs'] = ActivityLog::where('subject_type', 'owner')
            ->where('subject_id', $id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $data['meetings'] = \App\Models\Meeting::whereJsonContains('invitees', (string) $id)
            ->orWhereJsonContains('invitees', $id)
            ->orderByDesc('scheduled_at')
            ->limit(50)
            ->get();

        $data['votes'] = $owner->voteResponses
            ->pluck('vote')
            ->filter()
            ->unique('id')
            ->values();

        $data['is_restored'] = !is_null($owner->previous_account_id);
        $data['owner_settings'] = Setting::getByKey('owner_settings', [
            'delete_protection' => true,
            'national_address_required' => false,
        ]);

        $invoices = $owner->invoices;
        $vouchers = $owner->vouchers;
        $transactions = $owner->transactions;
        $vehicles = $owner->vehicles;
        $contracts = $owner->contracts;
        $maintenance = $owner->maintenanceRequests;
        $meetings = collect($data['meetings']);
        $votes = collect($data['votes']);

        $bookings = $owner->bookings()->with('facility')->get();
        $data['bookings'] = $bookings;

        $data['stats'] = [
            'vehicles' => [
                'total' => $vehicles->count(),
                'with_parking' => $vehicles->whereNotNull('parking_spot_id')->count(),
                'without_parking' => $vehicles->whereNull('parking_spot_id')->count(),
            ],
            'transactions' => [
                'total' => $transactions->count(),
                'total_amount' => $transactions->sum('amount'),
                'income' => $transactions->where('type', 'income')->sum('amount'),
                'expense' => $transactions->where('type', 'expense')->sum('amount'),
            ],
            'bookings' => [
                'total' => $bookings->count(),
                'approved' => $bookings->where('status', 'approved')->count(),
                'cancelled' => $bookings->whereNotNull('cancelled_at')->count(),
            ],
            'maintenance' => [
                'total' => $maintenance->count(),
                'open' => $maintenance->whereIn('status', ['open', 'pending', 'in_progress'])->count(),
                'completed' => $maintenance->where('status', 'completed')->count(),
            ],
            'contracts' => [
                'total' => $contracts->count(),
                'active' => $contracts->where('status', 'active')->count(),
                'expired' => $contracts->where('status', 'expired')->count(),
            ],
            'meetings' => [
                'total' => $meetings->count(),
                'votes_total' => $votes->count(),
            ],
            'invoices' => [
                'total' => $invoices->count(),
                'paid' => $invoices->where('status', 'paid')->count(),
                'unpaid' => $invoices->whereIn('status', ['unpaid', 'overdue'])->count(),
                'total_amount' => $invoices->sum('total_amount'),
                'paid_amount' => $invoices->where('status', 'paid')->sum('total_amount'),
            ],
            'vouchers' => [
                'total' => $vouchers->count(),
                'receipts' => $vouchers->where('type', 'receipt')->count(),
                'payments' => $vouchers->where('type', 'payment')->count(),
                'receipts_amount' => $vouchers->where('type', 'receipt')->sum('amount'),
                'payments_amount' => $vouchers->where('type', 'payment')->sum('amount'),
            ],
        ];

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $owner = Owner::findOrFail($id);
        $oldValues = $owner->only(['full_name', 'national_id', 'phone', 'email', 'status']);

        $data = $request->validate(array_merge([
            'full_name'   => ['sometimes', 'string', 'max:255'],
            'national_id' => ['sometimes', 'string', 'size:10', 'regex:/^\d{10}$/', new \App\Rules\UniqueEncrypted('owners', 'national_id_hash', ignoreId: (int) $id)],
            'phone'       => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/'],
            'email'       => ['nullable', 'email', 'max:255'],
            'avatar'      => ['nullable', 'image', 'max:2048'],
        ], $this->addressRules()), [
            'national_id.size'  => 'رقم الهوية يجب أن يكون 10 أرقام',
            'national_id.regex' => 'رقم الهوية يجب أن يكون 10 أرقام فقط',
            'phone.size'        => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex'       => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
        ]);

        unset($data['avatar']);
        $avatarPath = $this->handleAvatar($request, $owner);
        if ($avatarPath) $data['avatar'] = $avatarPath;

        if (!($data['has_national_address'] ?? $owner->has_national_address)) {
            $data = array_merge($data, [
                'address_type' => null, 'address_short_code' => null,
                'address_region' => null, 'address_city' => null,
                'address_district' => null, 'address_street' => null,
                'address_building_no' => null, 'address_additional_no' => null,
                'address_postal_code' => null, 'address_unit_no' => null,
            ]);
        }
        $owner->update($data);

        $newValues = $owner->fresh()->only(['full_name', 'national_id', 'phone', 'email', 'status']);
        ActivityLog::record('owner', $id, 'updated', 'تم تحديث بيانات المالك', $oldValues, $newValues);

        return response()->json([
            'message' => 'تم تحديث بيانات المالك بنجاح',
            'data'    => $owner->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $owner = Owner::with(['units', 'invoices', 'contracts'])->findOrFail($id);

        $ownerSettings = Setting::getByKey('owner_settings', []);
        $protectionEnabled = $ownerSettings['delete_protection'] ?? true;

        if ($protectionEnabled) {
            $linked = [];
            if ($owner->units->count() > 0)     $linked[] = 'وحدات عقارية';
            if ($owner->invoices->count() > 0)   $linked[] = 'فواتير';
            if ($owner->contracts->count() > 0)  $linked[] = 'عقود';

            if (!empty($linked)) {
                return response()->json([
                    'message' => 'لا يمكن حذف المالك لارتباطه بـ: ' . implode('، ', $linked) . '. سيتم أرشفة الحساب فقط مع الحفاظ على جميع البيانات.',
                    'has_links' => true,
                    'linked'    => $linked,
                ], 409);
            }
        }

        ActivityLog::record('owner', $id, 'deleted',
            'تم حذف حساب المالك (أرشفة)',
            $owner->only(['full_name', 'national_id', 'account_number'])
        );

        $owner->delete(); // SoftDeletes → sets deleted_at

        return response()->json([
            'message' => 'تم أرشفة حساب المالك بنجاح. يمكن استعادته عند التسجيل مجدداً بنفس رقم الهوية.',
        ]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $owner = Owner::findOrFail($id);
        $oldStatus = $owner->status;
        $owner->status = $owner->status === 'active' ? 'inactive' : 'active';
        $owner->save();

        ActivityLog::record('owner', $id, 'status_changed',
            $owner->status === 'active' ? 'تم تنشيط حساب المالك' : 'تم إيقاف حساب المالك',
            ['status' => $oldStatus],
            ['status' => $owner->status]
        );

        return response()->json([
            'message' => $owner->status === 'active' ? 'تم تنشيط الحساب' : 'تم إيقاف الحساب',
            'data'    => $owner,
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:owners,id'],
        ]);

        $blocked = [];
        $deleted = 0;
        foreach (Owner::with(['units', 'invoices', 'contracts'])->whereIn('id', $data['ids'])->get() as $owner) {
            if ($owner->units->count() > 0 || $owner->invoices->count() > 0 || $owner->contracts->count() > 0) {
                $blocked[] = $owner->full_name;
                continue;
            }
            ActivityLog::record('owner', $owner->id, 'deleted', 'تم أرشفة حساب المالك (حذف جماعي)',
                $owner->only(['full_name', 'national_id', 'account_number'])
            );
            $owner->delete();
            $deleted++;
        }

        $msg = "تم أرشفة {$deleted} مالك بنجاح";
        if (!empty($blocked)) {
            $msg .= '. لم يتم حذف: ' . implode('، ', $blocked) . ' لارتباطهم ببيانات.';
        }

        return response()->json(['message' => $msg, 'count' => $deleted, 'blocked' => $blocked]);
    }

    /* ── Export owners as CSV ── */
    public function export(): StreamedResponse
    {
        $filename = 'owners_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 recognition
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'رقم الحساب / Account No.',
                'اسم المالك / Owner Name',
                'رقم الهوية / National ID',
                'رقم الجوال / Phone',
                'البريد الإلكتروني / Email',
                'الحالة / Status',
            ]);

            Owner::query()->orderBy('account_number')->chunk(500, function ($owners) use ($handle) {
                foreach ($owners as $owner) {
                    fputcsv($handle, [
                        $owner->account_number,
                        $owner->full_name,
                        $owner->national_id,
                        $owner->phone,
                        $owner->email,
                        $owner->status,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* ── Import owners from CSV ── */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            return response()->json(['message' => 'Cannot read file'], 422);
        }

        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if (!$header || count($header) < 2) {
            fclose($handle);
            return response()->json(['message' => 'Invalid file format'], 422);
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $row = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            if (count($line) < 2) {
                $skipped++;
                continue;
            }

            $name = trim($line[0] ?? '');
            $nationalId = trim($line[1] ?? '');
            $phone = trim($line[2] ?? '');
            $email = trim($line[3] ?? '');

            if (empty($name) || empty($nationalId)) {
                $errors[] = "Row {$row}: missing name or national ID";
                $skipped++;
                continue;
            }

            if (!preg_match('/^\d{10}$/', $nationalId)) {
                $errors[] = "Row {$row}: national ID must be exactly 10 digits";
                $skipped++;
                continue;
            }

            if ($phone && !preg_match('/^05\d{8}$/', $phone)) {
                $errors[] = "Row {$row}: phone must be 10 digits starting with 05";
                $skipped++;
                continue;
            }

            if (Owner::where('national_id_hash', Owner::blindHash((string) $nationalId))->exists()) {
                $errors[] = "Row {$row}: national ID {$nationalId} already exists";
                $skipped++;
                continue;
            }

            Owner::create([
                'full_name'   => $name,
                'national_id' => $nationalId,
                'phone'       => $phone ?: null,
                'email'       => $email ?: null,
                'status'      => 'active',
            ]);
            $created++;
        }

        fclose($handle);

        return response()->json([
            'message' => "Imported {$created} owners, skipped {$skipped}",
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => array_slice($errors, 0, 10),
        ]);
    }

    /* ── Download template CSV for import ── */
    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'اسم المالك / Owner Name',
                'رقم الهوية / National ID',
                'رقم الجوال / Phone',
                'البريد الإلكتروني / Email',
            ]);
            fputcsv($handle, ['محمد أحمد', '1234567890', '0501234567', 'owner@example.com']);
            fclose($handle);
        }, 'owners_import_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
