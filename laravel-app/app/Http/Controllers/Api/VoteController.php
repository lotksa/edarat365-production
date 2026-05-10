<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Owner;
use App\Models\Vote;
use App\Models\VotePhase;
use App\Models\VoteResponse;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoteController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json([
            'total'     => Vote::count(),
            'active'    => Vote::where('status', 'active')->count(),
            'completed' => Vote::where('status', 'completed')->count(),
            'cancelled' => Vote::where('status', 'cancelled')->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Vote::with(['association', 'phases']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('vote_number', 'like', "%{$search}%");
            });
        }

        if ($v = $request->query('status'))          $query->where('status', $v);
        if ($v = $request->query('association_id'))   $query->where('association_id', $v);

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

    public function show(int $id): JsonResponse
    {
        $vote = Vote::with([
            'association',
            'phases',
            'responses.owner',
        ])->findOrFail($id);

        return response()->json(['data' => $vote]);
    }

    public function associationOwnersCount(int $associationId): JsonResponse
    {
        $count = Owner::whereHas('units.property', fn ($q) => $q->where('association_id', $associationId))
            ->distinct()
            ->count('owners.id');

        return response()->json(['count' => $count]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'             => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'association_id'    => ['required', 'exists:associations,id'],
            'meeting_id'        => ['nullable', 'exists:meetings,id'],
            'quorum_percentage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status'            => ['nullable', 'string', 'in:active,completed,cancelled'],
            'phases'            => ['required', 'array', 'size:3'],
            'phases.*.start_date' => ['required', 'date'],
            'phases.*.end_date'   => ['required', 'date', 'after_or_equal:phases.*.start_date'],
        ], [
            'title.required'        => 'عنوان التصويت مطلوب',
            'association_id.required' => 'الجمعية مطلوبة',
            'phases.required'       => 'مراحل التصويت مطلوبة',
            'phases.size'           => 'يجب إدخال 3 مراحل للتصويت',
        ]);

        $data['total_voters'] = Owner::whereHas('units.property', fn ($q) => $q->where('association_id', $data['association_id']))
            ->distinct()
            ->count('owners.id');

        if ($data['total_voters'] < 1) {
            return response()->json(['message' => 'لا يوجد ملاك مسجلين في هذه الجمعية'], 422);
        }

        // SECURITY: Server-attributed creator. Never trust client-supplied created_by.
        $data['created_by'] = auth()->id();

        $phasesInput = $data['phases'];
        unset($data['phases']);

        $vote = DB::transaction(function () use ($data, $phasesInput) {
            // Atomic vote_number sequence — eliminates race condition on concurrent creates.
            $lastId = (int) DB::table('votes')->lockForUpdate()->max('id');
            $data['vote_number'] = 'VT-' . date('Y') . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);
            $data['status'] = $data['status'] ?? 'active';
            $data['current_phase'] = 1;
            $data['quorum_percentage'] = $data['quorum_percentage'] ?? 75;

            $vote = Vote::create($data);

            foreach ($phasesInput as $i => $phaseData) {
                $phaseNumber = $i + 1;
                VotePhase::create([
                    'vote_id'      => $vote->id,
                    'phase_number' => $phaseNumber,
                    'start_date'   => $phaseData['start_date'],
                    'end_date'     => $phaseData['end_date'],
                    'status'       => $phaseNumber === 1 ? 'active' : 'pending',
                ]);
            }

            return $vote;
        });

        ActivityLog::record('vote', $vote->id, 'created', 'تم إنشاء تصويت — ' . $vote->title);

        Notifier::dispatch('vote.created', [
            'subject' => $vote,
            'data'    => [
                'title'  => $vote->title,
                'number' => $vote->vote_number,
            ],
        ]);

        return response()->json([
            'message' => 'تم إنشاء التصويت بنجاح',
            'data'    => $vote->fresh()->load(['association', 'phases']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vote = Vote::findOrFail($id);

        $data = $request->validate([
            'title'             => ['sometimes', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'association_id'    => ['nullable', 'exists:associations,id'],
            'quorum_percentage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status'            => ['nullable', 'string', 'in:active,completed,cancelled'],
        ]);
        // SECURITY: total_voters is a derived field; do NOT accept it from clients.

        $vote->update($data);
        ActivityLog::record('vote', $vote->id, 'updated', 'تم تحديث تصويت — ' . $vote->title);

        return response()->json([
            'message' => 'تم تحديث التصويت بنجاح',
            'data'    => $vote->fresh()->load(['association', 'phases']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $vote = Vote::findOrFail($id);
        ActivityLog::record('vote', $vote->id, 'deleted', 'تم حذف تصويت — ' . $vote->title);
        $vote->delete();

        return response()->json(['message' => 'تم حذف التصويت بنجاح']);
    }

    /**
     * Cast a vote.
     *
     * IDOR fix: a regular voter must NOT be able to vote on behalf of any other
     * owner_id. The owner is resolved server-side from the authenticated user's
     * linked Owner record (matched by email/phone). Only users with
     * votes.create / votes.update may cast on behalf of a specified owner_id
     * (admin/clerk scenario).
     */
    public function castVote(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'owner_id' => ['nullable', 'integer', 'exists:owners,id'],
            'response' => ['required', 'string', 'in:yes,no,abstain'],
        ], [
            'response.required' => 'الإجابة مطلوبة',
            'response.in'       => 'الإجابة يجب أن تكون: نعم، لا، أو امتناع',
        ]);

        $isClerk = $user && (
            $user->isSuperAdmin() ||
            $user->hasPermission('votes.create') ||
            $user->hasPermission('votes.update')
        );

        if (!empty($data['owner_id'])) {
            if (!$isClerk) {
                return response()->json([
                    'message' => 'لا يمكنك التصويت نيابةً عن مالك آخر',
                ], 403);
            }
            $ownerId = (int) $data['owner_id'];
        } else {
            // Resolve owner from authenticated user. Match owner by email or phone.
            $ownerId = null;
            if ($user) {
                $owner = Owner::query()
                    ->when(!empty($user->email), fn ($q) => $q->where('email', $user->email))
                    ->orWhere(function ($q) use ($user) {
                        if (!empty($user->phone)) $q->where('phone', $user->phone);
                    })
                    ->first();
                $ownerId = $owner?->id;
            }
            if (!$ownerId) {
                return response()->json([
                    'message' => 'لم يتم ربط حسابك بسجل مالك. تواصل مع الإدارة.',
                ], 422);
            }
        }

        return DB::transaction(function () use ($id, $ownerId, $data, $isClerk) {
            // Lock the vote row + active phase row to prevent quorum/rollover races.
            $vote = Vote::with('phases')->lockForUpdate()->findOrFail($id);

            if ($vote->status !== 'active') {
                return response()->json(['message' => 'التصويت غير نشط'], 422);
            }

            // Ensure the owner belongs to the vote's association.
            $belongsToAssociation = Owner::query()
                ->where('id', $ownerId)
                ->whereHas('units.property', fn ($q) => $q->where('association_id', $vote->association_id))
                ->exists();
            if (!$belongsToAssociation) {
                return response()->json([
                    'message' => 'هذا المالك ليس عضواً في الجمعية المعنية',
                ], 422);
            }

            $existing = VoteResponse::where('vote_id', $vote->id)
                ->where('owner_id', $ownerId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return response()->json(['message' => 'هذا المالك صوّت مسبقاً'], 422);
            }

            $activePhase = VotePhase::where('vote_id', $vote->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (!$activePhase) {
                return response()->json(['message' => 'لا توجد مرحلة نشطة حالياً'], 422);
            }

            VoteResponse::create([
                'vote_id'       => $vote->id,
                'vote_phase_id' => $activePhase->id,
                'owner_id'      => $ownerId,
                'response'      => $data['response'],
                'voted_at'      => now(),
            ]);

            $column = 'votes_' . $data['response'];
            $activePhase->increment($column);
            $activePhase->refresh();

            $totalVotesCast = $activePhase->votes_yes + $activePhase->votes_no + $activePhase->votes_abstain;
            $participationRate = $vote->total_voters > 0
                ? ($totalVotesCast / $vote->total_voters) * 100
                : 0;

            if ($participationRate >= $vote->quorum_percentage) {
                $activePhase->update(['quorum_met' => true, 'status' => 'completed']);
                $vote->update(['status' => 'completed']);

                ActivityLog::record('vote', $vote->id, 'completed', 'تم اكتمال التصويت — تم تحقيق النصاب في المرحلة ' . $activePhase->phase_number);
            }

            return response()->json([
                'message' => 'تم تسجيل التصويت بنجاح',
                'data'    => $vote->fresh()->load(['phases', 'responses.owner']),
            ]);
        });
    }

    public function advancePhase(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $vote = Vote::with('phases')->lockForUpdate()->findOrFail($id);

            if ($vote->status !== 'active') {
                return response()->json(['message' => 'التصويت غير نشط'], 422);
            }

            $activePhase = $vote->phases->where('status', 'active')->first();

            if (!$activePhase) {
                return response()->json(['message' => 'لا توجد مرحلة نشطة'], 422);
            }

            if ($vote->current_phase >= 3) {
                $activePhase->update(['status' => 'completed']);
                $vote->update(['status' => 'completed']);

                ActivityLog::record('vote', $vote->id, 'completed', 'تم إنهاء التصويت — انتهت جميع المراحل بدون نصاب');

                return response()->json([
                    'message' => 'انتهت جميع المراحل — تم إغلاق التصويت',
                    'data'    => $vote->fresh()->load(['phases']),
                ]);
            }

            $activePhase->update(['status' => 'completed']);

            $nextPhaseNumber = $vote->current_phase + 1;
            $nextPhase = $vote->phases->where('phase_number', $nextPhaseNumber)->first();

            if ($nextPhase) {
                $nextPhase->update(['status' => 'active']);
            }

            $vote->update(['current_phase' => $nextPhaseNumber]);

            ActivityLog::record('vote', $vote->id, 'phase_advanced', 'تم الانتقال إلى المرحلة ' . $nextPhaseNumber);

            return response()->json([
                'message' => 'تم الانتقال إلى المرحلة ' . $nextPhaseNumber,
                'data'    => $vote->fresh()->load(['phases']),
            ]);
        });
    }
}
