<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Sensitive fields per settings key — decrypted automatically when read.
     * Mirrors the SECRET_KEYS map in SettingsController.
     */
    private const SECRET_FIELDS = [
        'mail'         => ['smtp_password'],
        'sms'          => ['api_key'],
        'ai'           => ['api_key'],
        'turnstile'    => ['secret_key'],
        'integrations' => ['api_key', 'api_secret', 'access_token', 'webhook_secret'],
    ];

    /**
     * Returns the saved value with secret fields automatically decrypted.
     * Use this everywhere internally (OtpService, AiController, etc.).
     */
    public static function getByKey(string $key, array $default = []): array
    {
        $row = static::where('key', $key)->first();
        $value = $row?->value ?? $default;

        $secrets = self::SECRET_FIELDS[$key] ?? [];
        foreach ($secrets as $field) {
            if (!isset($value[$field]) || !is_string($value[$field]) || $value[$field] === '') continue;
            try {
                $value[$field] = Crypt::decryptString($value[$field]);
            } catch (\Throwable $e) {
                // Already plaintext (legacy) — leave as-is
            }
        }

        return $value;
    }

    /**
     * Persist a settings value verbatim. Caller is responsible for encrypting
     * any sensitive field (SettingsController handles this).
     */
    public static function setByKey(string $key, array $value): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
