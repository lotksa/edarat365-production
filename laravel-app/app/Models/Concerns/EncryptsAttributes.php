<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Application-level encryption for sensitive Eloquent attributes.
 *
 * - Uses Laravel's `Crypt` (AES-256-CBC with HMAC-SHA256, authenticated).
 * - Backwards-compatible: legacy plaintext rows decrypt to themselves
 *   so the upgrade can be applied to live databases without data loss.
 * - Models declare `protected array $encryptable = ['col1', 'col2', ...];`
 * - Optionally `$blindHashable = ['col1' => 'col1_hash', ...]` for searchable
 *   columns. The trait keeps the hash column up to date with HMAC-SHA256(value).
 *
 * Usage:
 *   use \App\Models\Concerns\EncryptsAttributes;
 *   protected array $encryptable = ['national_id', 'address_postal_code', ...];
 *   protected array $blindHashable = ['national_id' => 'national_id_hash'];
 *
 *   // Lookup: Owner::where('national_id_hash', Owner::blindHash('1234567890'))->first();
 */
trait EncryptsAttributes
{
    /**
     * Encrypt on the way in. Also maintain blind-index hash if declared.
     *
     * SECURITY: this method MUST fail closed. If Crypt::encryptString() throws
     * (e.g. APP_KEY is missing/corrupt), we do NOT silently fall back to
     * persisting the plaintext value — the operation aborts with an exception
     * so the caller can surface a 500 and the data never lands on disk in the
     * clear.
     */
    public function setAttribute($key, $value)
    {
        if ($this->isEncryptableAttribute($key) && $value !== null && $value !== '') {
            // Maintain blind hash BEFORE encryption (so we hash the cleartext)
            $hashCol = $this->blindHashColumn($key);
            if ($hashCol) {
                $this->attributes[$hashCol] = self::blindHash((string) $value);
            }

            try {
                $value = Crypt::encryptString((string) $value);
            } catch (\Throwable $e) {
                Log::error('Encryption failed for ' . static::class . '.' . $key, ['err' => $e->getMessage()]);
                // FAIL CLOSED: refuse to persist plaintext.
                throw new \RuntimeException(
                    'Encryption failed for ' . static::class . '.' . $key
                    . ' — refusing to write plaintext.'
                );
            }
        } elseif ($this->isEncryptableAttribute($key) && ($value === null || $value === '')) {
            $hashCol = $this->blindHashColumn($key);
            if ($hashCol) {
                $this->attributes[$hashCol] = null;
            }
        }
        return parent::setAttribute($key, $value);
    }

    /**
     * Decrypt on the way out.
     *
     * SECURITY: when decryption fails we MUST distinguish between two cases:
     *   1. The value is legacy plaintext → return as-is so existing rows
     *      continue to read correctly during migration.
     *   2. The value looks like our ciphertext but the MAC fails (truncation,
     *      APP_KEY mismatch, double-encryption, etc.) → return null instead
     *      of leaking the ciphertext blob into API responses / the UI.
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        if ($this->isEncryptableAttribute($key) && is_string($value) && $value !== '') {
            try {
                return Crypt::decryptString($value);
            } catch (\Throwable $e) {
                // Heuristic: Laravel's Crypt::encryptString output is base64
                // of a JSON envelope, so a base64-decoded value that starts
                // with '{"iv":' is one of ours. If it looks like ciphertext
                // but we can't read it, fail closed and hide the blob.
                $decoded = base64_decode($value, true);
                if (is_string($decoded) && str_starts_with($decoded, '{"iv":')) {
                    return null;
                }
                // Legacy plaintext → return as-is so existing rows still work
                return $value;
            }
        }
        return $value;
    }

    protected function isEncryptableAttribute(string $key): bool
    {
        return property_exists($this, 'encryptable')
            && is_array($this->encryptable)
            && in_array($key, $this->encryptable, true);
    }

    /**
     * Returns the matching `_hash` column for an encrypted attribute, or null.
     */
    protected function blindHashColumn(string $key): ?string
    {
        if (!property_exists($this, 'blindHashable') || !is_array($this->blindHashable)) {
            return null;
        }
        return $this->blindHashable[$key] ?? null;
    }

    /**
     * Deterministic HMAC-SHA256 of a normalized value, scoped by APP_KEY.
     * Used as a blind index for equality lookups on encrypted columns.
     * Static so it can be used in query builders: where('col_hash', self::blindHash($v)).
     */
    public static function blindHash(string $value): string
    {
        $secret = config('app.key');
        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7));
        }
        $normalized = trim(mb_strtolower($value));
        return hash_hmac('sha256', $normalized, $secret . '|blind_index');
    }

    /**
     * Returns the raw (already-encrypted) DB value for a column.
     * Useful for re-encryption commands.
     */
    public function getRawEncryptedAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
