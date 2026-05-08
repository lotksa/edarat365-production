<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaseMessage;
use App\Models\LegalCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseMessageController extends Controller
{
    public function index(Request $request, $caseId): JsonResponse
    {
        // Validate the case exists, defends against forced enumeration
        LegalCase::findOrFail($caseId);

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
        LegalCase::findOrFail($caseId);

        $data = $request->validate([
            'content'             => 'nullable|string|max:10000',
            'type'                => 'nullable|in:text,file,image,reminder,system',
            'reply_to_id'         => 'nullable|exists:case_messages,id',
            'attachments'         => 'nullable|array|max:10',
            'attachments.*.name'  => 'nullable|string|max:255',
            'attachments.*.path'  => 'nullable|string|max:1024',
            'attachments.*.size'  => 'nullable|integer|min:0',
            'attachments.*.mime'  => 'nullable|string|max:100',
        ]);

        $data['legal_case_id'] = $caseId;
        // SECURITY: sender is always the authenticated user — never trust client.
        $data['sender_id'] = auth()->id();
        $data['type'] = $data['type'] ?? 'text';

        $msg = CaseMessage::create($data);
        $msg->load('sender', 'replyTo.sender');

        return response()->json($msg, 201);
    }

    public function uploadAttachment(Request $request, $caseId): JsonResponse
    {
        LegalCase::findOrFail($caseId);

        // SECURITY: strict mime + extension whitelist. Block executables, HTML, SVG, etc.
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif,webp',
            ],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        // Generate a non-guessable filename to prevent path-style attacks.
        $safeName = bin2hex(random_bytes(16)) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
        $path = $file->storeAs("case-messages/{$caseId}", $safeName, 'public');

        return response()->json([
            'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
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

    /**
     * Mark unread messages (sent by other users) as read.
     *
     * Bug fix: previous query had broken AND/OR precedence (the orWhereRaw
     * broke out of the (sender_id != ?) scope and returned ALL messages from
     * other cases). Wrapped in an explicit nested where().
     */
    public function markRead(Request $request, $caseId): JsonResponse
    {
        LegalCase::findOrFail($caseId);

        $userId = auth()->id();

        $userIdJson = json_encode($userId);

        CaseMessage::where('legal_case_id', $caseId)
            ->where('sender_id', '!=', $userId)
            ->where(function ($q) use ($userIdJson) {
                $q->whereNull('read_by')
                  ->orWhereRaw("NOT JSON_CONTAINS(COALESCE(read_by, JSON_ARRAY()), ?)", [$userIdJson]);
            })
            ->each(function ($msg) use ($userId) {
                $readBy = $msg->read_by ?? [];
                if (!is_array($readBy)) $readBy = [];
                if (!in_array($userId, $readBy, true)) {
                    $readBy[] = $userId;
                    $msg->update(['read_by' => $readBy]);
                }
            });

        return response()->json(['message' => 'marked']);
    }
}
