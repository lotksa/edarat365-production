<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaseReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseReminderController extends Controller
{
    public function index($caseId): JsonResponse
    {
        $reminders = CaseReminder::where('legal_case_id', $caseId)
            ->with('creator')
            ->orderBy('remind_at')
            ->get();

        return response()->json($reminders);
    }

    public function store(Request $request, $caseId): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'remind_at'   => 'required|date',
        ]);

        $data['legal_case_id'] = $caseId;
        $data['created_by'] = auth()->id();

        $reminder = CaseReminder::create($data);
        $reminder->load('creator');

        return response()->json($reminder, 201);
    }

    public function update(Request $request, $caseId, $remId): JsonResponse
    {
        $reminder = CaseReminder::where('legal_case_id', $caseId)->findOrFail($remId);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'remind_at'   => 'sometimes|date',
            'status'      => 'nullable|in:pending,sent,dismissed',
        ]);

        $reminder->update($data);
        return response()->json($reminder);
    }

    public function dismiss($caseId, $remId): JsonResponse
    {
        $reminder = CaseReminder::where('legal_case_id', $caseId)->findOrFail($remId);
        $reminder->update(['status' => 'dismissed']);

        return response()->json($reminder);
    }

    public function destroy($caseId, $remId): JsonResponse
    {
        CaseReminder::where('legal_case_id', $caseId)->findOrFail($remId)->delete();
        return response()->json(['message' => 'deleted']);
    }
}
