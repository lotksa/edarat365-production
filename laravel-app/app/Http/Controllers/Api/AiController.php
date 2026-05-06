<?php

namespace App\Http\Controllers\Api;

use App\Models\Association;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\MaintenanceRequest;
use App\Models\Meeting;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Setting;
use App\Models\Unit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiController extends Controller
{
    private const PROVIDERS = [
        'openai' => [
            'url'    => 'https://api.openai.com/v1/chat/completions',
            'format' => 'openai',
        ],
        'deepseek' => [
            'url'    => 'https://api.deepseek.com/v1/chat/completions',
            'format' => 'openai',
        ],
        'groq' => [
            'url'    => 'https://api.groq.com/openai/v1/chat/completions',
            'format' => 'openai',
        ],
        'openrouter' => [
            'url'    => 'https://openrouter.ai/api/v1/chat/completions',
            'format' => 'openai',
        ],
        'gemini' => [
            'url'    => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
            'format' => 'gemini',
        ],
        'ollama' => [
            'url'    => 'http://localhost:11434/api/chat',
            'format' => 'ollama',
        ],
        'custom' => [
            'url'    => '',
            'format' => 'openai',
        ],
    ];

    private function getAiConfig(): array
    {
        $cfg = Setting::getByKey('ai', []);
        return [
            'api_key'    => $cfg['api_key'] ?? '',
            'model'      => $cfg['model'] ?? 'gpt-4o-mini',
            'provider'   => $cfg['provider'] ?? 'openai',
            'custom_url' => $cfg['custom_url'] ?? '',
        ];
    }

    private function getProviderUrl(string $provider, string $model, string $customUrl = ''): string
    {
        if ($provider === 'custom' && $customUrl) {
            return $customUrl;
        }

        $cfg = self::PROVIDERS[$provider] ?? self::PROVIDERS['openai'];
        return str_replace('{model}', $model, $cfg['url']);
    }

    private function getProviderFormat(string $provider): string
    {
        return self::PROVIDERS[$provider]['format'] ?? 'openai';
    }

    private function callAi(array $cfg, array $messages, int $maxTokens = 1000, float $temperature = 0.7): ?string
    {
        $provider = $cfg['provider'];
        $format   = $this->getProviderFormat($provider);
        $url      = $this->getProviderUrl($provider, $cfg['model'], $cfg['custom_url'] ?? '');

        if ($format === 'gemini') {
            return $this->callGemini($cfg, $messages, $maxTokens, $temperature);
        }

        if ($format === 'ollama') {
            return $this->callOllama($cfg, $messages);
        }

        $headers = ['Content-Type' => 'application/json'];

        $http = Http::withHeaders($headers)->timeout(30);

        if ($provider !== 'ollama' && !empty($cfg['api_key'])) {
            $http = $http->withToken($cfg['api_key']);
        }

        if ($provider === 'openrouter') {
            $http = $http->withHeaders([
                'HTTP-Referer'    => config('app.url', 'https://edarat365.com'),
                'X-Title'         => 'Edarat365',
            ]);
        }

        $response = $http->post($url, [
            'model'       => $cfg['model'],
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ]);

        if ($response->ok()) {
            return $response->json('choices.0.message.content');
        }

        return null;
    }

    private function callGemini(array $cfg, array $messages, int $maxTokens, float $temperature): ?string
    {
        $url = $this->getProviderUrl('gemini', $cfg['model']) . '?key=' . $cfg['api_key'];

        $contents = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            if ($msg['role'] === 'system') {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $msg['content']]]];
                $contents[] = ['role' => 'model', 'parts' => [['text' => 'فهمت. سأساعدك.']]];
                continue;
            }
            $contents[] = ['role' => $role, 'parts' => [['text' => $msg['content']]]];
        }

        $response = Http::timeout(30)->post($url, [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature'     => $temperature,
            ],
        ]);

        if ($response->ok()) {
            return $response->json('candidates.0.content.parts.0.text');
        }

        return null;
    }

    private function callOllama(array $cfg, array $messages): ?string
    {
        $url = $cfg['custom_url'] ?: 'http://localhost:11434/api/chat';

        $response = Http::timeout(60)->post($url, [
            'model'    => $cfg['model'],
            'messages' => $messages,
            'stream'   => false,
        ]);

        if ($response->ok()) {
            return $response->json('message.content');
        }

        return null;
    }

    private function gatherPlatformContext(): string
    {
        $owners       = Owner::selectRaw("count(*) as total")->first();
        $associations = Association::selectRaw("count(*) as total, sum(status='active') as active")->first();
        $properties   = Property::selectRaw("count(*) as total, sum(type='residential') as residential, sum(type='commercial') as commercial")->first();
        $units        = Unit::selectRaw("count(*) as total, sum(status='occupied') as occupied, sum(status='vacant') as vacant, sum(status='under_maintenance') as maint")->first();
        $invoices     = Invoice::selectRaw("count(*) as total, sum(status='paid') as paid, sum(status='pending') as pending, sum(status='overdue') as overdue, sum(amount) as total_amount")->first();
        $maintenance  = MaintenanceRequest::selectRaw("count(*) as total, sum(status='completed') as completed, sum(status='in_progress') as in_progress, sum(status='pending') as pending, sum(status='overdue') as overdue")->first();
        $legalCases   = LegalCase::selectRaw("count(*) as total, sum(status='open') as open_cases")->first();
        $contracts    = Contract::selectRaw("count(*) as total, sum(status='active') as active, sum(status='expired') as expired")->first();
        $meetings     = Meeting::selectRaw("count(*) as total, sum(scheduled_at >= now()) as upcoming")->first();

        $recentMaint = MaintenanceRequest::orderByDesc('created_at')->limit(3)->get(['title','priority','status','created_at']);
        $recentInv   = Invoice::orderByDesc('created_at')->limit(3)->get(['amount','due_date','status']);
        $overdueInv  = Invoice::where('status', 'overdue')->orderBy('due_date')->limit(5)->get(['amount','due_date']);

        return "
بيانات منصة إدارات 365 الحالية:

الملاك: الإجمالي={$owners->total}
الجمعيات: الإجمالي={$associations->total}, نشطة={$associations->active}
العقارات: الإجمالي={$properties->total}, سكني={$properties->residential}, تجاري={$properties->commercial}
الوحدات: الإجمالي={$units->total}, مشغولة={$units->occupied}, شاغرة={$units->vacant}, تحت الصيانة={$units->maint}
الفواتير: الإجمالي={$invoices->total}, مسددة={$invoices->paid}, معلقة={$invoices->pending}, متأخرة={$invoices->overdue}, إجمالي المبالغ={$invoices->total_amount} ريال
الصيانة: الإجمالي={$maintenance->total}, منجزة={$maintenance->completed}, قيد التنفيذ={$maintenance->in_progress}, معلقة={$maintenance->pending}, متأخرة={$maintenance->overdue}
القضايا: الإجمالي={$legalCases->total}, مفتوحة={$legalCases->open_cases}
العقود: الإجمالي={$contracts->total}, نشطة={$contracts->active}, منتهية={$contracts->expired}
الاجتماعات: الإجمالي={$meetings->total}, قادمة={$meetings->upcoming}

آخر طلبات الصيانة: " . $recentMaint->toJson() . "
آخر الفواتير: " . $recentInv->toJson() . "
الفواتير المتأخرة: " . $overdueInv->toJson();
    }

    public function chat(Request $request): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $cfg = $this->getAiConfig();
        $needsKey = !in_array($cfg['provider'], ['ollama']);

        if ($needsKey && empty($cfg['api_key'])) {
            return response()->json([
                'reply'  => 'لم يتم تهيئة مفتاح API للذكاء الاصطناعي. يرجى إضافته من الإعدادات > الذكاء الاصطناعي.',
                'status' => 'no_key',
            ]);
        }

        $context = $this->gatherPlatformContext();
        $systemPrompt = "أنت مساعد ذكي لمنصة إدارات 365 لإدارة اتحاد الملاك والعقارات. اسمك 'مساعد إدارات'.
أنت تساعد المسؤول في:
1. فهم بيانات المنصة وتحليلها
2. تقديم توصيات لتحسين الأداء
3. التنبيه على المشكلات المحتملة (فواتير متأخرة، صيانة معلقة، عقود منتهية)
4. الإجابة عن أي سؤال يتعلق بإدارة العقارات واتحاد الملاك
5. اقتراح إجراءات ذكية

التزم بالعربية إلا إذا طلب المستخدم الإنجليزية. كن مختصراً ومفيداً.

{$context}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...collect($request->input('history', []))->map(fn($m) => [
                'role'    => $m['role'],
                'content' => $m['content'],
            ])->toArray(),
            ['role' => 'user', 'content' => $request->input('message')],
        ];

        try {
            $reply = $this->callAi($cfg, $messages, 1000, 0.7);

            if ($reply) {
                return response()->json(['reply' => $reply, 'status' => 'ok']);
            }

            return response()->json([
                'reply'  => 'حدث خطأ في الاتصال بخدمة الذكاء الاصطناعي. تأكد من صحة مفتاح API والإعدادات.',
                'status' => 'error',
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'reply'  => 'تعذر الاتصال بخدمة الذكاء الاصطناعي: ' . $e->getMessage(),
                'status' => 'error',
            ], 500);
        }
    }

    public function insights(): JsonResponse
    {
        $cfg = $this->getAiConfig();
        $needsKey = !in_array($cfg['provider'], ['ollama']);

        if ($needsKey && empty($cfg['api_key'])) {
            return response()->json(['insights' => $this->getLocalInsights(), 'source' => 'local']);
        }

        $context = $this->gatherPlatformContext();
        $prompt = "بناءً على البيانات التالية، أعطني 4-6 رؤى ذكية مختصرة (كل رؤية سطر واحد) تشمل:
- تنبيهات عاجلة (فواتير متأخرة، صيانة معلقة)
- توصيات لتحسين الأداء
- ملاحظات على البيانات
أرجع النتيجة كـ JSON array من objects بالشكل: [{\"type\":\"warning|info|success|tip\",\"text\":\"النص\"}]
فقط JSON بدون أي نص إضافي.

{$context}";

        try {
            $reply = $this->callAi($cfg, [['role' => 'user', 'content' => $prompt]], 600, 0.5);

            if ($reply) {
                $raw = preg_replace('/^```json\s*|```$/m', '', trim($reply));
                $insights = json_decode($raw, true);
                if (is_array($insights) && count($insights) > 0) {
                    return response()->json(['insights' => $insights, 'source' => 'ai']);
                }
            }
        } catch (\Exception $e) {
            // fallback
        }

        return response()->json(['insights' => $this->getLocalInsights(), 'source' => 'local']);
    }

    private function getLocalInsights(): array
    {
        $insights = [];

        $overdue = Invoice::where('status', 'overdue')->count();
        if ($overdue > 0) {
            $insights[] = ['type' => 'warning', 'text' => "يوجد {$overdue} فاتورة متأخرة تحتاج متابعة عاجلة"];
        }

        $pendingMaint = MaintenanceRequest::where('status', 'pending')->count();
        if ($pendingMaint > 0) {
            $insights[] = ['type' => 'warning', 'text' => "يوجد {$pendingMaint} طلب صيانة معلق بانتظار المعالجة"];
        }

        $overdueMaint = MaintenanceRequest::where('status', 'overdue')->count();
        if ($overdueMaint > 0) {
            $insights[] = ['type' => 'warning', 'text' => "يوجد {$overdueMaint} طلب صيانة متأخر يحتاج تدخل فوري"];
        }

        $expiredContracts = Contract::where('status', 'expired')->count();
        if ($expiredContracts > 0) {
            $insights[] = ['type' => 'info', 'text' => "يوجد {$expiredContracts} عقد منتهي يحتاج تجديد"];
        }

        $vacantUnits = Unit::where('status', 'vacant')->count();
        $totalUnits = Unit::count();
        if ($totalUnits > 0 && $vacantUnits > 0) {
            $pct = round(($vacantUnits / $totalUnits) * 100);
            $insights[] = ['type' => 'info', 'text' => "نسبة الوحدات الشاغرة {$pct}% ({$vacantUnits} من {$totalUnits})"];
        }

        $openCases = LegalCase::where('status', 'open')->count();
        if ($openCases > 0) {
            $insights[] = ['type' => 'info', 'text' => "يوجد {$openCases} قضية مفتوحة قيد المتابعة"];
        }

        $upcoming = Meeting::where('scheduled_at', '>=', now())->count();
        if ($upcoming > 0) {
            $insights[] = ['type' => 'success', 'text' => "لديك {$upcoming} اجتماع قادم مجدول"];
        }

        if (empty($insights)) {
            $insights[] = ['type' => 'success', 'text' => 'كل شيء يسير بشكل جيد! لا توجد تنبيهات حالياً'];
        }

        return $insights;
    }

    public function suggest(Request $request): JsonResponse
    {
        $request->validate([
            'context' => 'required|string',
            'field'   => 'required|string',
            'value'   => 'nullable|string',
        ]);

        $cfg = $this->getAiConfig();
        $needsKey = !in_array($cfg['provider'], ['ollama']);
        if ($needsKey && empty($cfg['api_key'])) {
            return response()->json(['suggestions' => []]);
        }

        $prompt = "أنت مساعد لمنصة إدارة عقارات. المستخدم يعمل في سياق: {$request->context}
الحقل: {$request->field}
القيمة الحالية: {$request->value}
أعطني 3 اقتراحات مناسبة كـ JSON array من strings فقط بدون أي نص إضافي.";

        try {
            $reply = $this->callAi($cfg, [['role' => 'user', 'content' => $prompt]], 200, 0.6);

            if ($reply) {
                $raw = preg_replace('/^```json\s*|```$/m', '', trim($reply));
                $suggestions = json_decode($raw, true);
                if (is_array($suggestions)) {
                    return response()->json(['suggestions' => $suggestions]);
                }
            }
        } catch (\Exception $e) {
            // silent
        }

        return response()->json(['suggestions' => []]);
    }
}
