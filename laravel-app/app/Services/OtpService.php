<?php

namespace App\Services;

use App\Models\LoginOtp;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OtpService
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_PHONE = 'phone';

    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    /** Max OTP verification attempts before invalidating the code. */
    public const MAX_VERIFY_ATTEMPTS = 5;

    public function detectChannel(string $identifier): string
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) ? self::CHANNEL_EMAIL : self::CHANNEL_PHONE;
    }

    /**
     * Mask a PII identifier for safe logging (no full email/phone in logs).
     */
    private function maskIdentifier(string $identifier): string
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            [$name, $domain] = explode('@', $identifier, 2) + ['', ''];
            $maskedName = strlen($name) <= 2 ? $name : substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));
            return $maskedName . '@' . $domain;
        }
        $digits = preg_replace('/\D/', '', $identifier) ?? '';
        return strlen($digits) >= 4 ? str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4) : '****';
    }

    /**
     * Cryptographically-secure 6-digit OTP. No predictable values in any environment.
     */
    public function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * HMAC-SHA256 hash of the OTP using APP_KEY as the secret. Constant-time
     * compare via hash_equals() during verification prevents timing leaks.
     */
    public function hashCode(string $code): string
    {
        $secret = config('app.key');
        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7));
        }
        return hash_hmac('sha256', $code, $secret);
    }

    public function createOtp(string $identifier, string $channel, string $purpose, int $minutes = 10): LoginOtp
    {
        $code = $this->generateCode();

        $otp = LoginOtp::query()->create([
            'identifier' => Str::lower(trim($identifier)),
            'channel'    => $channel,
            'purpose'    => $purpose,
            'code'       => null,
            'code_hash'  => $this->hashCode($code),
            'expires_at' => now()->addMinutes($minutes),
            'attempts'   => 0,
        ]);

        $otp->setAttribute('plain_code', $code);

        return $otp;
    }

    /**
     * Constant-time verification. Returns true if hashes match.
     */
    public function verifyCode(LoginOtp $otp, string $candidate): bool
    {
        $expected = (string) $otp->code_hash;
        if ($expected === '') return false;
        return hash_equals($expected, $this->hashCode($candidate));
    }

    public function send(LoginOtp $otp, ?string $userName = null): array
    {
        $templates = Setting::getByKey('mail_templates', $this->defaultMailTemplates());
        $smsTemplates = Setting::getByKey('sms_templates', $this->defaultSmsTemplates());

        $minutes = max(1, (int) round(now()->diffInSeconds(Carbon::parse($otp->expires_at), false) / 60)) ?: 10;

        $plainCode = $otp->plain_code ?? '';

        $vars = [
            '{{code}}' => $plainCode,
            '{{minutes}}' => (string) $minutes,
            '{{name}}' => $userName ?? '',
            '{{app_name}}' => 'Edarat365',
        ];

        if ($otp->channel === self::CHANNEL_EMAIL) {
            $key = $otp->purpose === self::PURPOSE_PASSWORD_RESET ? 'password_reset' : 'verification';
            $tpl = $templates[$key] ?? $this->defaultMailTemplates()[$key];
            $branding = Setting::getByKey('mail_branding', $this->defaultMailBranding());
            $branding = array_replace($this->defaultMailBranding(), is_array($branding) ? $branding : []);

            $codeVars = $vars;
            $codeVars['{{code}}'] = '<strong style="font-size:32px;font-weight:800;letter-spacing:10px;color:'
                . htmlspecialchars($branding['code_color'], ENT_QUOTES) . ';background:'
                . htmlspecialchars($branding['code_bg'], ENT_QUOTES) . ';padding:14px 22px;border-radius:10px;display:inline-block;direction:ltr;">'
                . htmlspecialchars($plainCode, ENT_QUOTES) . '</strong>';

            $subject = strtr($tpl['subject'] ?? '', $vars);
            $innerBody = strtr($tpl['body'] ?? '', $codeVars);
            $body = $this->renderBrandedMail($innerBody, $branding);

            $sent = $this->sendEmail($otp->identifier, $subject, $body);

            return [
                'channel' => 'email',
                'sent'    => $sent,
                // Never preview the code in any environment — codes ride only via the chosen channel.
                'preview' => null,
            ];
        }

        $key = $otp->purpose === self::PURPOSE_PASSWORD_RESET ? 'password_reset' : 'verification';
        $tpl = $smsTemplates[$key] ?? $this->defaultSmsTemplates()[$key];
        $message = strtr($tpl['body'] ?? '', $vars);
        $sent = $this->sendSms($otp->identifier, $message);

        return [
            'channel' => 'phone',
            'sent'    => $sent,
            'preview' => null,
        ];
    }

    public function defaultMailTemplates(): array
    {
        return [
            'verification' => [
                'subject' => 'رمز التحقق - {{app_name}}',
                'body' => '<p style="margin:0 0 12px;">مرحباً {{name}}،</p>'
                    . '<p style="margin:0 0 18px;">رمز التحقق الخاص بك لتسجيل الدخول هو:</p>'
                    . '<div style="text-align:center;margin:22px 0;">{{code}}</div>'
                    . '<p style="margin:0;color:#64748b;font-size:14px;">صالح لمدة {{minutes}} دقيقة. إذا لم تطلب هذا الرمز يمكنك تجاهل الرسالة.</p>',
            ],
            'password_reset' => [
                'subject' => 'إعادة تعيين كلمة المرور - {{app_name}}',
                'body' => '<p style="margin:0 0 12px;">مرحباً {{name}}،</p>'
                    . '<p style="margin:0 0 18px;">رمز إعادة تعيين كلمة المرور:</p>'
                    . '<div style="text-align:center;margin:22px 0;">{{code}}</div>'
                    . '<p style="margin:0;color:#64748b;font-size:14px;">صالح لمدة {{minutes}} دقيقة. إذا لم تطلب إعادة التعيين تجاهل الرسالة.</p>',
            ],
        ];
    }

    public function defaultMailBranding(): array
    {
        return [
            'logo_url' => '/brand/logo-dark.png',
            'logo_variant' => 'white',
            'logo_max_height' => 56,
            'header_bg' => '#021B4A',
            'body_bg' => '#f1f5f9',
            'card_bg' => '#FFFFFF',
            'border_color' => '#e5e7eb',
            'text_color' => '#1e293b',
            'muted_color' => '#64748b',
            'accent_color' => '#021B4A',
            'code_bg' => '#f1f5f9',
            'code_color' => '#021B4A',
            'footer_bg' => '#021B4A',
            'footer_text_color' => '#FFFFFF',
            'footer_text_ar' => 'جميع الحقوق محفوظة لمنصة إدارات 365 © 2026',
            'footer_text_en' => '© 2026 Edarat365. All rights reserved.',
        ];
    }

    private function renderBrandedMail(string $innerBody, array $b): string
    {
        $base = rtrim(config('app.url') ?: 'https://edarat365.lotksa.com', '/');
        $logo = $b['logo_url'] ?? '/brand/logo-dark.png';
        if (!preg_match('~^https?://~i', $logo)) {
            $logo = $base . (str_starts_with($logo, '/') ? '' : '/') . $logo;
        }
        $logoH = (int) ($b['logo_max_height'] ?? 56);
        $footer = $b['footer_text_ar'] ?? 'جميع الحقوق محفوظة لمنصة إدارات 365';

        $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

        return '<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:0;background:' . $esc($b['body_bg']) . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $esc($b['body_bg']) . ';padding:28px 12px;font-family:Tajawal,Segoe UI,Arial,sans-serif;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:' . $esc($b['card_bg']) . ';border:1px solid ' . $esc($b['border_color']) . ';border-radius:16px;overflow:hidden;box-shadow:0 4px 18px rgba(2,27,74,0.06);">'
            . '<tr><td align="center" style="background:' . $esc($b['header_bg']) . ';padding:28px 24px;">'
            . '<img src="' . $esc($logo) . '" alt="Edarat365" height="' . $logoH . '" style="display:block;margin:0 auto;height:' . $logoH . 'px;width:auto;max-width:80%;" />'
            . '</td></tr>'
            . '<tr><td style="padding:30px 28px;color:' . $esc($b['text_color']) . ';font-size:15px;line-height:1.7;direction:rtl;text-align:right;">'
            . $innerBody
            . '</td></tr>'
            . '<tr><td align="center" style="background:' . $esc($b['footer_bg']) . ';color:' . $esc($b['footer_text_color']) . ';padding:16px 24px;font-size:13px;">'
            . $esc($footer)
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';
    }

    public function defaultSmsTemplates(): array
    {
        return [
            'verification' => [
                'body' => 'رمز التحقق الخاص بك في {{app_name}}: {{code}} (صالح {{minutes}} دقيقة)',
            ],
            'password_reset' => [
                'body' => 'رمز إعادة تعيين كلمة المرور في {{app_name}}: {{code}} (صالح {{minutes}} دقيقة)',
            ],
        ];
    }

    private function sendEmail(string $to, string $subject, string $htmlBody): bool
    {
        try {
            $mailSettings = Setting::getByKey('mail', []);
            $this->configureMailRuntime($mailSettings);

            // SECURITY: strip CR/LF from headers to prevent email header
            // injection (CRLF attacks) when subject/from come from settings.
            $safeSubject = trim(preg_replace('/[\r\n]+/', ' ', (string) $subject) ?? '');
            if ($safeSubject === '') $safeSubject = 'Edarat365';
            $safeFrom = isset($mailSettings['from_address'])
                ? trim(preg_replace('/[\r\n]+/', '', (string) $mailSettings['from_address']) ?? '')
                : '';
            $safeFromName = isset($mailSettings['from_name'])
                ? trim(preg_replace('/[\r\n]+/', ' ', (string) $mailSettings['from_name']) ?? '')
                : 'Edarat365';

            Mail::send([], [], function (Message $message) use ($to, $safeSubject, $htmlBody, $safeFrom, $safeFromName) {
                $message->to($to);
                $message->subject($safeSubject);
                $message->html($htmlBody);

                if ($safeFrom !== '' && filter_var($safeFrom, FILTER_VALIDATE_EMAIL)) {
                    $message->from($safeFrom, $safeFromName ?: 'Edarat365');
                }
            });

            return true;
        } catch (\Throwable $e) {
            // SECURITY: never log raw PII (email/phone). Use a masked identifier.
            Log::warning('OTP email failed', [
                'to_masked' => $this->maskIdentifier($to),
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function configureMailRuntime(array $settings): void
    {
        if (empty($settings['smtp_host'])) {
            return;
        }

        Config::set('mail.mailers.smtp.host', $settings['smtp_host']);
        Config::set('mail.mailers.smtp.port', (int) ($settings['smtp_port'] ?? 587));
        Config::set('mail.mailers.smtp.encryption', $settings['smtp_encryption'] ?? 'tls');
        Config::set('mail.mailers.smtp.username', $settings['smtp_username'] ?? null);
        Config::set('mail.mailers.smtp.password', $settings['smtp_password'] ?? null);

        if (!empty($settings['from_address'])) {
            Config::set('mail.from.address', $settings['from_address']);
            Config::set('mail.from.name', $settings['from_name'] ?? 'Edarat365');
        }
    }

    private function sendSms(string $to, string $message): bool
    {
        try {
            $sms = Setting::getByKey('sms', []);
            if (empty($sms['provider']) || empty($sms['api_url']) || empty($sms['api_key'])) {
                Log::info('SMS provider not configured; skipping send.', ['to_masked' => $this->maskIdentifier($to)]);
                return false;
            }

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $sms['api_key'],
                'Accept' => 'application/json',
            ])->post($sms['api_url'], [
                'to' => $to,
                'sender' => $sms['sender_name'] ?? 'Edarat365',
                'message' => $message,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('OTP SMS failed', [
                'to_masked' => $this->maskIdentifier($to),
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}
