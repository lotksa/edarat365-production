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

    public function detectChannel(string $identifier): string
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) ? self::CHANNEL_EMAIL : self::CHANNEL_PHONE;
    }

    public function generateCode(): string
    {
        if (app()->environment('local')) {
            return '111111';
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function createOtp(string $identifier, string $channel, string $purpose, int $minutes = 10): LoginOtp
    {
        $code = $this->generateCode();

        return LoginOtp::query()->create([
            'identifier' => Str::lower(trim($identifier)),
            'channel' => $channel,
            'purpose' => $purpose,
            'code' => $code,
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    public function send(LoginOtp $otp, ?string $userName = null): array
    {
        $templates = Setting::getByKey('mail_templates', $this->defaultMailTemplates());
        $smsTemplates = Setting::getByKey('sms_templates', $this->defaultSmsTemplates());

        $minutes = max(1, (int) round(now()->diffInSeconds(Carbon::parse($otp->expires_at), false) / 60)) ?: 10;

        $vars = [
            '{{code}}' => $otp->code,
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
                . htmlspecialchars((string) ($vars['{{code}}'] ?? ''), ENT_QUOTES) . '</strong>';

            $subject = strtr($tpl['subject'] ?? '', $vars);
            $innerBody = strtr($tpl['body'] ?? '', $codeVars);
            $body = $this->renderBrandedMail($innerBody, $branding);

            $sent = $this->sendEmail($otp->identifier, $subject, $body);

            return [
                'channel' => 'email',
                'sent' => $sent,
                'preview' => app()->environment('local') ? $otp->code : null,
            ];
        }

        $key = $otp->purpose === self::PURPOSE_PASSWORD_RESET ? 'password_reset' : 'verification';
        $tpl = $smsTemplates[$key] ?? $this->defaultSmsTemplates()[$key];
        $message = strtr($tpl['body'] ?? '', $vars);
        $sent = $this->sendSms($otp->identifier, $message);

        return [
            'channel' => 'phone',
            'sent' => $sent,
            'preview' => app()->environment('local') ? $otp->code : null,
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

            Mail::send([], [], function (Message $message) use ($to, $subject, $htmlBody, $mailSettings) {
                $message->to($to);
                $message->subject($subject);
                $message->html($htmlBody);

                if (!empty($mailSettings['from_address'])) {
                    $message->from($mailSettings['from_address'], $mailSettings['from_name'] ?? 'Edarat365');
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('OTP email failed: ' . $e->getMessage(), ['to' => $to]);
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
                Log::info('SMS provider not configured; skipping send.', ['to' => $to]);
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
            Log::warning('OTP SMS failed: ' . $e->getMessage(), ['to' => $to]);
            return false;
        }
    }
}
