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
use Illuminate\Support\Facades\Log;

class AiController extends Controller
{
    private const PROVIDERS = [
        'openai' => [
            'url'    => 'https://api.openai.com/v1/chat/completions',
            'format' => 'openai',
        ],
        'deepseek' => [
            'url'    => 'https://api.deepseek.com/chat/completions',
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

    private function getAiConfig(?array $override = null): array
    {
        $cfg = $override ?? Setting::getByKey('ai', []);
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
        if ($provider === 'ollama' && $customUrl) {
            return $customUrl;
        }
        $cfg = self::PROVIDERS[$provider] ?? self::PROVIDERS['openai'];
        return str_replace('{model}', $model, $cfg['url']);
    }

    private function getProviderFormat(string $provider): string
    {
        return self::PROVIDERS[$provider]['format'] ?? 'openai';
    }

    /**
     * Call the AI provider and return a structured result with both content and error info.
     *
     * @return array{ok:bool, content:?string, error:?string, http_code:?int, raw:?array}
     */
    private function callAi(array $cfg, array $messages, int $maxTokens = 1000, float $temperature = 0.7, int $timeout = 60): array
    {
        $provider = $cfg['provider'];
        $format   = $this->getProviderFormat($provider);

        try {
            if ($format === 'gemini') {
                return $this->callGemini($cfg, $messages, $maxTokens, $temperature, $timeout);
            }
            if ($format === 'ollama') {
                return $this->callOllama($cfg, $messages, $timeout);
            }
            return $this->callOpenAiCompatible($cfg, $messages, $maxTokens, $temperature, $timeout);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'ok' => false,
                'content' => null,
                'error' => 'تعذر الاتصال بخدمة المزود (انتهاء المهلة أو DNS): ' . $e->getMessage(),
                'http_code' => null,
                'raw' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('AI call exception', ['provider' => $provider, 'error' => $e->getMessage()]);
            return [
                'ok' => false,
                'content' => null,
                'error' => 'خطأ غير متوقع: ' . $e->getMessage(),
                'http_code' => null,
                'raw' => null,
            ];
        }
    }

    private function callOpenAiCompatible(array $cfg, array $messages, int $maxTokens, float $temperature, int $timeout): array
    {
        $provider = $cfg['provider'];
        $url = $this->getProviderUrl($provider, $cfg['model'], $cfg['custom_url'] ?? '');

        if (empty($url)) {
            return [
                'ok' => false,
                'content' => null,
                'error' => 'لم يتم تحديد رابط الخدمة (Custom URL).',
                'http_code' => null,
                'raw' => null,
            ];
        }

        $http = Http::acceptJson()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout($timeout);

        if (!empty($cfg['api_key'])) {
            $http = $http->withToken($cfg['api_key']);
        }

        if ($provider === 'openrouter') {
            $http = $http->withHeaders([
                'HTTP-Referer' => config('app.url', 'https://edarat365.com'),
                'X-Title'      => 'Edarat365',
            ]);
        }

        $payload = [
            'model'       => $cfg['model'],
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ];

        $response = $http->post($url, $payload);
        $json = $response->json() ?: [];

        if ($response->successful()) {
            $content = $json['choices'][0]['message']['content'] ?? null;
            if ($content === null || $content === '') {
                return [
                    'ok' => false,
                    'content' => null,
                    'error' => 'استجابة فارغة من المزود (لا يوجد content). تحقق من اسم النموذج.',
                    'http_code' => $response->status(),
                    'raw' => $json,
                ];
            }
            return [
                'ok' => true,
                'content' => $content,
                'error' => null,
                'http_code' => $response->status(),
                'raw' => $json,
            ];
        }

        $errMsg = $this->extractErrorMessage($json, $response->body());
        return [
            'ok' => false,
            'content' => null,
            'error' => sprintf('[HTTP %d] %s', $response->status(), $errMsg),
            'http_code' => $response->status(),
            'raw' => $json ?: ['body' => substr($response->body(), 0, 500)],
        ];
    }

    private function callGemini(array $cfg, array $messages, int $maxTokens, float $temperature, int $timeout): array
    {
        if (empty($cfg['api_key'])) {
            return ['ok' => false, 'content' => null, 'error' => 'مفتاح API مفقود.', 'http_code' => null, 'raw' => null];
        }

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

        $response = Http::acceptJson()->timeout($timeout)->post($url, [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature'     => $temperature,
            ],
        ]);

        $json = $response->json() ?: [];

        if ($response->successful()) {
            $content = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!$content) {
                $blockReason = $json['promptFeedback']['blockReason'] ?? null;
                return [
                    'ok' => false,
                    'content' => null,
                    'error' => $blockReason
                        ? 'تم حجب المحتوى بواسطة Gemini: ' . $blockReason
                        : 'استجابة فارغة من Gemini.',
                    'http_code' => $response->status(),
                    'raw' => $json,
                ];
            }
            return ['ok' => true, 'content' => $content, 'error' => null, 'http_code' => $response->status(), 'raw' => $json];
        }

        $errMsg = $this->extractErrorMessage($json, $response->body());
        return [
            'ok' => false,
            'content' => null,
            'error' => sprintf('[HTTP %d] %s', $response->status(), $errMsg),
            'http_code' => $response->status(),
            'raw' => $json ?: ['body' => substr($response->body(), 0, 500)],
        ];
    }

    private function callOllama(array $cfg, array $messages, int $timeout): array
    {
        $url = $cfg['custom_url'] ?: 'http://localhost:11434/api/chat';

        $response = Http::acceptJson()->timeout($timeout)->post($url, [
            'model'    => $cfg['model'],
            'messages' => $messages,
            'stream'   => false,
        ]);

        $json = $response->json() ?: [];

        if ($response->successful()) {
            $content = $json['message']['content'] ?? null;
            if (!$content) {
                return ['ok' => false, 'content' => null, 'error' => 'استجابة فارغة من Ollama.', 'http_code' => $response->status(), 'raw' => $json];
            }
            return ['ok' => true, 'content' => $content, 'error' => null, 'http_code' => $response->status(), 'raw' => $json];
        }

        $errMsg = $this->extractErrorMessage($json, $response->body());
        return [
            'ok' => false,
            'content' => null,
            'error' => sprintf('[HTTP %d] %s', $response->status(), $errMsg),
            'http_code' => $response->status(),
            'raw' => $json ?: ['body' => substr($response->body(), 0, 500)],
        ];
    }

    /**
     * Try to extract a human-readable error message from various provider response shapes.
     */
    private function extractErrorMessage(array $json, string $rawBody): string
    {
        // OpenAI-style: { error: { message, type, code } }
        if (isset($json['error']['message'])) {
            $msg = $json['error']['message'];
            if (isset($json['error']['type'])) $msg .= ' (' . $json['error']['type'] . ')';
            return $msg;
        }
        // OpenAI alt: { error: "string" }
        if (isset($json['error']) && is_string($json['error'])) {
            return $json['error'];
        }
        // Gemini-style: { error: { message, status, code } }
        if (isset($json['message'])) {
            return $json['message'];
        }
        // Ollama-style: { error: "..." }
        if (isset($json['detail'])) {
            return is_string($json['detail']) ? $json['detail'] : json_encode($json['detail']);
        }
        // OpenRouter-style: { error: { message, code } } already handled above

        $body = trim($rawBody);
        if ($body === '') return 'لا توجد تفاصيل من المزود.';
        return mb_substr($body, 0, 300);
    }

    /**
     * Lightweight test connection endpoint. Sends a tiny "ping" prompt and
     * returns minimal diagnostics. Sensitive fields like raw payloads or
     * stack traces are NEVER returned to the client.
     * Accepts optional override (api_key, provider, model, custom_url) so the
     * user can test before saving.
     */
    public function test(Request $request): JsonResponse
    {
        $request->validate([
            'api_key'    => 'nullable|string|max:500',
            // SECURITY: only allow known providers.
            'provider'   => 'nullable|string|in:openai,deepseek,groq,openrouter,gemini,ollama,custom',
            'model'      => 'nullable|string|max:200',
            // SECURITY: custom_url must be a public HTTPS URL. Blocks SSRF to
            // localhost/loopback/private ranges and unencrypted HTTP.
            'custom_url' => ['nullable', 'string', 'max:500', 'url', new \App\Rules\PublicHttpsUrl()],
        ]);

        $override = array_filter($request->only(['api_key', 'provider', 'model', 'custom_url']), fn ($v) => $v !== null && $v !== '');

        $saved = Setting::getByKey('ai', []);
        $merged = array_merge(is_array($saved) ? $saved : [], $override);
        $cfg = $this->getAiConfig($merged);

        if (empty($cfg['provider'])) {
            return response()->json([
                'ok' => false,
                'reply' => 'لم يتم تحديد المزود.',
                'error' => 'provider missing',
            ], 422);
        }

        if (!in_array($cfg['provider'], ['ollama']) && empty($cfg['api_key'])) {
            return response()->json([
                'ok' => false,
                'reply' => 'مفتاح API مفقود.',
                'error' => 'api_key missing',
            ], 422);
        }

        $messages = [
            ['role' => 'system', 'content' => 'You are a connectivity probe. Reply with one short Arabic word.'],
            ['role' => 'user',   'content' => 'قل: نجح'],
        ];

        $startedAt = microtime(true);
        $result = $this->callAi($cfg, $messages, 32, 0.2, 30);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        // SECURITY: never return the raw provider response (may contain echoed
        // headers, debug data). Only return short, sanitized error message.
        return response()->json([
            'ok'         => $result['ok'],
            'reply'      => $result['content'] ? mb_substr($result['content'], 0, 300) : null,
            'error'      => $result['error'] ? mb_substr((string) $result['error'], 0, 300) : null,
            'http_code'  => $result['http_code'],
            'elapsed_ms' => $elapsedMs,
            'provider'   => $cfg['provider'],
            'model'      => $cfg['model'],
        ], $result['ok'] ? 200 : 422);
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

        $result = $this->callAi($cfg, $messages, 1000, 0.7, 60);

        if ($result['ok']) {
            return response()->json(['reply' => $result['content'], 'status' => 'ok']);
        }

        // SECURITY: send a short, generic message to end users. Detailed errors
        // are written to the server log only.
        Log::info('AI provider call failed', [
            'provider'  => $cfg['provider'],
            'http_code' => $result['http_code'],
            'error'     => $result['error'],
        ]);
        return response()->json([
            'reply'  => 'تعذر الاتصال بخدمة الذكاء الاصطناعي. يرجى المحاولة لاحقاً أو التواصل مع المسؤول.',
            'status' => 'error',
            'http_code' => $result['http_code'],
            'provider'  => $cfg['provider'],
            'model'     => $cfg['model'],
        ], 200); // 200 so the frontend can read the body easily
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

        $result = $this->callAi($cfg, [['role' => 'user', 'content' => $prompt]], 600, 0.5, 45);

        if ($result['ok'] && $result['content']) {
            $raw = preg_replace('/^```json\s*|```$/m', '', trim($result['content']));
            $insights = json_decode($raw, true);
            if (is_array($insights) && count($insights) > 0) {
                return response()->json(['insights' => $insights, 'source' => 'ai']);
            }
        }

        return response()->json([
            'insights' => $this->getLocalInsights(),
            'source'   => 'local',
            'ai_error' => $result['error'] ?? null,
        ]);
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

        $result = $this->callAi($cfg, [['role' => 'user', 'content' => $prompt]], 200, 0.6, 30);

        if ($result['ok'] && $result['content']) {
            $raw = preg_replace('/^```json\s*|```$/m', '', trim($result['content']));
            $suggestions = json_decode($raw, true);
            if (is_array($suggestions)) {
                return response()->json(['suggestions' => $suggestions]);
            }
        }

        return response()->json(['suggestions' => [], 'ai_error' => $result['error'] ?? null]);
    }
}
