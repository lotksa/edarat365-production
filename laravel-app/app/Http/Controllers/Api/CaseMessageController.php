<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaseMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseMessageController extends Controller
{
    public function index(Request $request, $caseId): JsonResponse
    {
        $query = CaseMessage::where('legal_case_id', $caseId)
            ->with('sender', 'replyTo.sender');

        if ($s = $request->query('search')) {
            $query->where('content', 'like', "%{$s}%");
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $pinned = (clone $query)->where('is_pinned', true)->orderBy('created_at', 'desc')->get();
        $messages = $query->orderBy('created_at', 'asc')->paginate($request->query('per_page', 50));

        return response()->json([
            'pinned'   => $pinned,
            'messages' => $messages,
        ]);
    }

    public function store(Request $request, $caseId): JsonResponse
    {
        $data = $request->validate([
            'content'     => 'nullable|string',
            'type'        => 'nullable|in:text,file,image,reminder,system',
            'reply_to_id' => 'nullable|exists:case_messages,id',
            'attachments' => 'nullable|array',
        ]);

        $data['legal_case_id'] = $caseId;
        $data['sender_id'] = auth()->id();
        $data['type'] = $data['type'] ?? 'text';

        $msg = CaseMessage::create($data);
        $msg->load('sender', 'replyTo.sender');

        return response()->json($msg, 201);
    }

    public function uploadAttachment(Request $request, $caseId): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:10240']);

        $file = $request->file('file');
        $path = $file->store("case-messages/{$caseId}", 'public');

        return response()->json([
            'name' => $file->getClientOriginalName(),
            'path' => '/storage/' . $path,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);
    }

    public function pin($caseId, $msgId): JsonResponse
    {
        $msg = CaseMessage::where('legal_case_id', $caseId)->findOrFail($msgId);
        $msg->update(['is_pinned' => !$msg->is_pinned]);

        return response()->json($msg);
    }

    public function markRead(Request $request, $caseId): JsonResponse
    {
        $userId = auth()->id();

        CaseMessage::where('legal_case_id', $caseId)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_by')
            ->orWhereRaw("NOT JSON_CONTAINS(COALESCE(read_by, '[]'), ?)", [json_encode($userId)])
            ->each(function ($msg) use ($userId) {
                $readBy = $msg->read_by ?? [];
                if (!in_array($userId, $readBy)) {
                    $readBy[] = $userId;
                    $msg->update(['read_by' => $readBy]);
                }
            });

        return response()->json(['message' => 'marked']);
    }
}
