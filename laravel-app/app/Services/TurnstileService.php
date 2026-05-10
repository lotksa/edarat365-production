<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cloudflare Turnstile (CAPTCHA) verification.
 *
 * Settings live under the `turnstile` key (managed in SettingsTurnstile.vue):
 *   {
 *     enabled:    bool,
 *     site_key:   string,   // public — embedded in the SPA
 *     secret_key: string,   // server-side only — never returned to browser
 *     pages:      { admin_login: bool, owner_login: bool },
 *   }
 *
 * The frontend widget posts the token to /auth/* endpoints under the field
 * `cf-turnstile-response` (or its alias `turnstile_token`). This service
 * verifies that token against Cloudflare's siteverify API. If the feature is
 * not enabled or the page is not protected, verification is a no-op.
 */
class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Page identifiers that the settings UI exposes. */
    public const PAGE_ADMIN_LOGIN = 'admin_login';
    public const PAGE_OWNER_LOGIN = 'owner_login';

    /**
     * Returns the public-safe slice of the settings (for the frontend bootstrap
     * call). NEVER includes the secret_key.
     *
     * @return array{enabled:bool, site_key:string, pages:array<string,bool>}
     */
    public function publicConfig(): array
    {
        $cfg = $this->settings();
        $pages = is_array($cfg['pages'] ?? null) ? $cfg['pages'] : [];

        return [
            'enabled'  => $this->isFullyConfigured($cfg),
            'site_key' => (string) ($cfg['site_key'] ?? ''),
            'pages'    => [
                self::PAGE_ADMIN_LOGIN => (bool) ($pages[self::PAGE_ADMIN_LOGIN] ?? false),
                self::PAGE_OWNER_LOGIN => (bool) ($pages[self::PAGE_OWNER_LOGIN] ?? false),
            ],
        ];
    }

    /**
     * True when the named page is currently protected by Turnstile.
     * Both global enable AND page-level toggle must be on, AND both keys present.
     */
    public function isProtected(string $page): bool
    {
        $cfg = $this->settings();
        if (!$this->isFullyConfigured($cfg)) return false;
        $pages = is_array($cfg['pages'] ?? null) ? $cfg['pages'] : [];
        return (bool) ($pages[$page] ?? false);
    }

    /**
     * Validates the request's Turnstile token if the page is protected. Throws
     * a ValidationException with a translated message when verification fails
     * so the caller can surface the error in the standard 422 envelope.
     */
    public function assertVerified(Request $request, string $page): void
    {
        if (!$this->isProtected($page)) return;

        $token = $this->extractToken($request);
        if ($token === null || $token === '') {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => 'يرجى إكمال التحقق الأمني (Turnstile)',
            ]);
        }

        $cfg = $this->settings();
        $secret = (string) ($cfg['secret_key'] ?? '');
        if ($secret === '') {
            // Fail-closed: refusing the request is safer than letting it through.
            Log::warning('Turnstile is enabled but no secret_key is configured.');
            throw ValidationException::withMessages([
                'cf-turnstile-response' => 'تعذّر التحقق الأمني — أعد المحاولة لاحقاً',
            ]);
        }

        $remoteIp = $request->ip();
        $ok = $this->verifyToken($secret, $token, $remoteIp);

        if (!$ok) {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => 'فشل التحقق الأمني — حدّث الصفحة وحاول مجدداً',
            ]);
        }
    }

    /**
     * Low-level call to Cloudflare's siteverify endpoint. Returns false on
     * failure or any network/parse error (fail-closed).
     */
    public function verifyToken(string $secret, string $token, ?string $remoteIp = null): bool
    {
        try {
            /** @var PendingRequest $client */
            $client = Http::asForm()->timeout(8)->connectTimeout(5);
            $response = $client->post(self::VERIFY_URL, array_filter([
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $remoteIp,
            ]));
        } catch (\Throwable $e) {
            // Network errors must not be a free pass — fail closed.
            Log::warning('Turnstile siteverify network failure', [
                'err' => $e->getMessage(),
            ]);
            return false;
        }

        if (!$response->ok()) {
            Log::warning('Turnstile siteverify non-200', [
                'status' => $response->status(),
            ]);
            return false;
        }

        $body = $response->json();
        if (!is_array($body)) return false;

        return (bool) ($body['success'] ?? false);
    }

    /**
     * The widget submits the token as either the standard Cloudflare field
     * (`cf-turnstile-response`) or the snake_case alias we use in the SPA
     * (`turnstile_token`). Header `X-Turnstile-Token` is also accepted as
     * a defense-in-depth fallback.
     */
    private function extractToken(Request $request): ?string
    {
        $candidates = [
            $request->input('cf-turnstile-response'),
            $request->input('turnstile_token'),
            $request->header('X-Turnstile-Token'),
        ];

        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') return $c;
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function settings(): array
    {
        // Reads the settings row and decrypts secret_key automatically
        // (handled by the Setting model's getByKey helper).
        return Setting::getByKey('turnstile', [
            'enabled'    => false,
            'site_key'   => '',
            'secret_key' => '',
            'pages'      => [
                self::PAGE_ADMIN_LOGIN => false,
                self::PAGE_OWNER_LOGIN => false,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function isFullyConfigured(array $cfg): bool
    {
        return (bool) ($cfg['enabled'] ?? false)
            && is_string($cfg['site_key'] ?? null) && $cfg['site_key'] !== ''
            && is_string($cfg['secret_key'] ?? null) && $cfg['secret_key'] !== '';
    }
}
