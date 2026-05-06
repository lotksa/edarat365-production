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
            $subject = strtr($tpl['subject'] ?? '', $vars);
            $body = strtr($tpl['body'] ?? '', $vars);

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
                'body' => "<div style=\"direction:rtl;font-family:Tajawal,Arial,sans-serif;background:#f8fafc;padding:24px;\">"
                    . "<div style=\"max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;\">"
                    . "<div style=\"background:#021B4A;color:#fff;padding:20px 24px;\"><h2 style=\"margin:0;font-size:18px;\">{{app_name}}</h2></div>"
                    . "<div style=\"padding:28px 24px;color:#1e293b;\">"
                    . "<p style=\"margin:0 0 12px;\">مرحباً {{name}},</p>"
                    . "<p style=\"margin:0 0 18px;\">رمز التحقق الخاص بك لتسجيل الدخول هو:</p>"
                    . "<div style=\"text-align:center;font-size:32px;font-weight:800;letter-spacing:10px;color:#021B4A;background:#f1f5f9;padding:16px;border-radius:10px;margin:18px 0;\">{{code}}</div>"
                    . "<p style=\"margin:0;color:#64748b;font-size:14px;\">صالح لمدة {{minutes}} دقيقة. إذا لم تطلب هذا الرمز، يمكنك تجاهل هذه الرسالة.</p>"
                    . "</div></div></div>",
            ],
            'password_reset' => [
                'subject' => 'إعادة تعيين كلمة المرور - {{app_name}}',
                'body' => "<div style=\"direction:rtl;font-family:Tajawal,Arial,sans-serif;background:#f8fafc;padding:24px;\">"
                    . "<div style=\"max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;\">"
                    . "<div style=\"background:#021B4A;color:#fff;padding:20px 24px;\"><h2 style=\"margin:0;font-size:18px;\">{{app_name}}</h2></div>"
                    . "<div style=\"padding:28px 24px;color:#1e293b;\">"
                    . "<p style=\"margin:0 0 12px;\">مرحباً {{name}},</p>"
                    . "<p style=\"margin:0 0 18px;\">رمز إعادة تعيين كلمة المرور:</p>"
                    . "<div style=\"text-align:center;font-size:32px;font-weight:800;letter-spacing:10px;color:#dc2626;background:#fef2f2;padding:16px;border-radius:10px;margin:18px 0;\">{{code}}</div>"
                    . "<p style=\"margin:0;color:#64748b;font-size:14px;\">صالح لمدة {{minutes}} دقيقة. إذا لم تطلب إعادة التعيين، تجاهل هذه الرسالة.</p>"
                    . "</div></div></div>",
            ],
        ];
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
