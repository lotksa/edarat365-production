<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Association;
use App\Models\Meeting;
use App\Models\MeetingFollowupReminder;
use App\Models\Setting;
use App\Services\AssociationContext;
use App\Services\MeetingFollowup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MeetingFollowupController extends Controller
{
    private function scope(Request $request, ?int $associationId = null): void
    {
        $header = $request->header('X-Association-Id');
        if ($header && $associationId && (string) $header !== (string) $associationId) {
            abort(404);
        }
        if ($associationId) {
            Association::findOrFail($associationId);
        }
        app(AssociationContext::class)->id = $associationId;
    }

    public function index(Request $request, MeetingFollowup $service)
    {
        $input = $request->validate(['year' => ['sometimes', 'integer', 'between:2000,2100'],
            'association_id' => ['nullable', 'integer', 'exists:associations,id']]);
        $year = (int) ($input['year'] ?? now('Asia/Riyadh')->year);
        $id = isset($input['association_id']) ? (int) $input['association_id'] : null;
        $this->scope($request, $id);
        try {
            $settings = $service->settings();
            $associations = Association::when($id, fn ($q) => $q->whereKey($id))->orderBy('name')->get();
            $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'Asia/Riyadh')->utc();
            $meetings = Meeting::with('association:id,name,name_en')->where('scheduled_at', '>=', $start)->where('scheduled_at', '<', $start->addYear())->orderBy('scheduled_at')->get();
            $plans = $associations->map(fn ($a) => $service->plan($a, $meetings, $year, $settings));

            return response()->json(['data' => $plans, 'meetings' => $meetings, 'settings' => $settings,
                'reminders' => MeetingFollowupReminder::when($id, fn ($q) => $q->where('association_id', $id))->where('year', $year)->latest('id')->limit(100)->get(),
                'summary' => ['held' => $plans->sum('held'), 'required' => $plans->sum('required'),
                    'scheduled' => $plans->sum('scheduled'), 'attention' => $plans->whereIn('level', ['bad', 'warn'])->count(),
                    'overdue' => $plans->where('level', 'bad')->count()]]);
        } finally {
            app(AssociationContext::class)->id = null;
        }
    }

    public function show(Request $request, int $id, MeetingFollowup $service)
    {
        $meeting = Meeting::findOrFail($id);
        abort_unless($meeting->association_id, 404);
        $this->scope($request, $meeting->association_id);
        try {
            return response()->json(['data' => $meeting->load('association:id,name,name_en'),
                'ownership' => $service->ownership($meeting->association_id),
                'reminders' => MeetingFollowupReminder::where('association_id', $meeting->association_id)
                    ->where(fn ($q) => $q->where('meeting_id', $id)->orWhereNull('meeting_id'))->latest('id')->limit(100)->get()]);
        } finally {
            app(AssociationContext::class)->id = null;
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'association_id' => ['required', 'integer', 'exists:associations,id'],
            'title' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['annual', 'midyear', 'yearend', 'extra'])],
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'],
            'invitation_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:date'],
            'meeting_format' => ['required', Rule::in(['online', 'onsite', 'hybrid'])],
            'location' => ['required', 'string', 'max:500'], 'agenda' => ['required', 'string', 'max:20000'],
            'history' => ['sometimes', 'boolean'], 'previous_meeting_id' => ['nullable', 'integer'],
        ]);
        $date = CarbonImmutable::parse($data['date'].' '.$data['time'], 'Asia/Riyadh');
        $today = CarbonImmutable::now('Asia/Riyadh');
        if (! empty($data['history']) ? $date->isAfter($today) : $date->isBefore($today)) {
            throw ValidationException::withMessages(['date' => ['اختر موعدًا مستقبليًا للجدولة أو استخدم توثيق اجتماع سابق.']]);
        }
        if (CarbonImmutable::parse($data['invitation_date'], 'Asia/Riyadh')->startOfDay()->diffInDays($date->startOfDay()) < 5) {
            throw ValidationException::withMessages(['invitation_date' => ['مهلة الدعوة خمسة أيام على الأقل.']]);
        }
        if ($data['meeting_format'] !== 'onsite' && ! preg_match('#^https?://[^\s]+$#i', $data['location'])) {
            throw ValidationException::withMessages(['location' => ['أدخل رابط اجتماع صالحًا يبدأ بـ https:// أو http://.']]);
        }
        $this->scope($request, (int) $data['association_id']);
        try {
            return DB::transaction(function () use ($data, $date) {
                $previous = null;
                if (! empty($data['previous_meeting_id'])) {
                    $previous = Meeting::lockForUpdate()->findOrFail($data['previous_meeting_id']);
                    if ($previous->status !== 'no_quorum' || $previous->type !== $data['type'] || $previous->meeting_call !== 1 || $date->lessThanOrEqualTo($previous->scheduled_at)
                        || Meeting::where('previous_meeting_id', $previous->id)->whereNot('status', 'cancelled')->exists()) {
                        throw ValidationException::withMessages(['previous_meeting_id' => ['يجب ربط الاجتماع الثاني باجتماع أول لنفس الجمعية والنوع لم يكتمل نصابه، دون موعد ثانٍ قائم.']]);
                    }
                }
                $meeting = Meeting::create([
                    'association_id' => $data['association_id'], 'meeting_number' => 'MTG-'.now('Asia/Riyadh')->format('Y').'-'.strtoupper(bin2hex(random_bytes(5))),
                    'title' => $data['title'], 'type' => $data['type'], 'scheduled_at' => $date->utc(),
                    'invitation_date' => $data['invitation_date'], 'meeting_format' => $data['meeting_format'],
                    'location' => $data['location'], 'is_remote' => $data['meeting_format'] !== 'onsite',
                    'remote_platform' => $data['meeting_format'] !== 'onsite' ? 'manual' : null,
                    'remote_link' => $data['meeting_format'] !== 'onsite' ? $data['location'] : null,
                    'agenda' => $data['agenda'], 'status' => ! empty($data['history']) ? 'draft' : 'scheduled',
                    'meeting_call' => $previous ? 2 : 1, 'previous_meeting_id' => $previous?->id,
                ]);
                ActivityLog::record('meeting', $meeting->id, 'created', 'تسجيل اجتماع للمتابعة — '.$meeting->title);

                return response()->json(['data' => $meeting, 'message' => 'تم حفظ الاجتماع'], 201);
            });
        } finally {
            app(AssociationContext::class)->id = null;
        }
    }

    public function document(Request $request, int $id, MeetingFollowup $service)
    {
        $data = $request->validate(['minutes' => ['required', 'string', 'max:100000'],
            'signed' => ['required', 'accepted'], 'attendees' => ['present', 'array'],
            'attendees.*' => ['required', 'integer', 'distinct']]);
        $meeting = Meeting::findOrFail($id);
        abort_unless($meeting->association_id, 404);
        $this->scope($request, $meeting->association_id);
        try {
            return DB::transaction(function () use ($meeting, $data, $service) {
                $meeting = Meeting::lockForUpdate()->findOrFail($meeting->id);
                if (! in_array($meeting->status, ['scheduled', 'draft']) || $meeting->documented_at || $meeting->scheduled_at->isFuture()) {
                    throw ValidationException::withMessages(['minutes' => ['يمكن توثيق اجتماع حل موعده ولم يُوثق أو يُلغَ سابقًا.']]);
                }
                $ownership = $service->ownership($meeting->association_id);
                if (collect($data['attendees'])->diff(array_column($ownership['owners'], 'owner_id'))->isNotEmpty()) {
                    throw ValidationException::withMessages(['attendees' => ['يوجد مالك خارج نطاق ملكية وحدات هذه الجمعية.']]);
                }
                if ($ownership['incomplete']) {
                    throw ValidationException::withMessages(['attendees' => ['أكمل مساحات الوحدات ونسب ملكيتها قبل حساب النصاب.']]);
                }
                $represented = collect($ownership['owners'])->whereIn('owner_id', $data['attendees'])->sum('area');
                $percent = 100 * $represented / $ownership['total_area'];
                $passed = $meeting->meeting_call === 2 ? $represented > 0 : $percent + 0.000001 >= 75;
                $snapshot = $ownership + ['represented_area' => $represented, 'percentage' => round($percent, 4),
                    'attendee_ids' => $data['attendees'], 'meeting_call' => $meeting->meeting_call, 'passed' => $passed];
                $meeting->update(['minutes' => trim($data['minutes']), 'attendees' => array_map(fn ($id) => ['owner_id' => $id, 'status' => 'present'], $data['attendees']),
                    'status' => $passed ? 'completed' : 'no_quorum', 'documented_at' => now(),
                    'minutes_signed_at' => now(), 'quorum_snapshot' => $snapshot]);
                ActivityLog::record('meeting', $meeting->id, 'documented', ($passed ? 'توثيق اجتماع ومحضره — ' : 'تسجيل عدم اكتمال النصاب — ').$meeting->title);

                return response()->json(['data' => $meeting->fresh(), 'message' => $passed ? 'تم توثيق الاجتماع' : 'تم حفظ عدم اكتمال النصاب مع المحضر والحضور']);
            });
        } finally {
            app(AssociationContext::class)->id = null;
        }
    }

    public function settings(MeetingFollowup $service)
    {
        return response()->json(['data' => $service->settings(), 'associations' => Association::orderBy('name')->get(['id', 'name', 'name_en'])]);
    }

    public function updateSettings(Request $request, MeetingFollowup $service)
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'between:2,4'], 'fiscalMonth' => ['required', 'integer', 'between:1,12'],
            'midMonth' => ['required', 'integer', 'between:1,12'], 'yearEndMonth' => ['required', 'integer', 'between:1,12'],
            'yearEndEnabled' => ['required', 'boolean'], 'offsets' => ['required', 'array', 'min:1'],
            'offsets.*' => ['integer', 'distinct', Rule::in([60, 30, 14, 7])], 'urgent' => ['required', 'integer', 'between:1,30'],
            'recipients' => ['required', 'array', 'min:1'], 'recipients.*' => ['distinct', Rule::in(['chair', 'manager'])],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => ['distinct', Rule::in(['inapp', 'email', 'sms'])],
            'auto' => ['required', 'boolean'], 'fiscalOverrides' => ['present', 'array'],
            'fiscalOverrides.*' => ['integer', 'between:1,12'],
        ]);
        foreach (array_keys($data['fiscalOverrides']) as $id) {
            if (! ctype_digit((string) $id) || ! Association::whereKey($id)->exists()) {
                throw ValidationException::withMessages(['fiscalOverrides' => ['إحدى الجمعيات غير موجودة.']]);
            }
        }
        Setting::setByKey('followup_settings', $data);
        ActivityLog::record('settings', Setting::where('key', 'followup_settings')->value('id'), 'updated', 'تحديث إعدادات متابعة الاجتماعات');

        return response()->json(['data' => $service->settings(), 'message' => 'تم حفظ إعدادات المتابعة']);
    }

    public function reminder(Request $request)
    {
        $data = $request->validate(['association_id' => ['required', 'integer', 'exists:associations,id'],
            'meeting_id' => ['nullable', 'integer'], 'year' => ['required', 'integer', 'between:2000,2100'],
            'channel' => ['required', Rule::in(['inapp', 'email', 'sms'])],
            'recipients' => ['required', 'array', 'min:1'], 'recipients.*' => ['distinct', Rule::in(['chair', 'manager'])],
            'message' => ['required', 'string', 'max:5000']]);
        $this->scope($request, (int) $data['association_id']);
        try {
            if (! empty($data['meeting_id'])) {
                Meeting::findOrFail($data['meeting_id']);
            }
            // Match the prototype's review-and-record action. Recording never claims delivery.
            $row = MeetingFollowupReminder::create($data + ['created_by' => $request->user()->id, 'status' => 'recorded']);
            ActivityLog::record('association', (int) $data['association_id'], 'reminder_recorded', 'تسجيل تذكير متابعة دون إرسال خارجي');

            return response()->json(['data' => $row, 'message' => 'تم تسجيل التذكير؛ لم يتم إرسال بريد أو SMS'], 201);
        } finally {
            app(AssociationContext::class)->id = null;
        }
    }
}
