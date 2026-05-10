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
     *
     * NOTE: this is also called recursively by `attributesToArray()` below so
     * a single implementation handles both direct property access (`$m->col`)
     * and JSON serialization (`response()->json($model)`).
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        if ($this->isEncryptableAttribute($key) && is_string($value) && $value !== '') {
            return $this->decryptEncryptableValue($value);
        }
        return $value;
    }

    /**
     * Override `attributesToArray()` so JSON / array serialization decrypts
     * encryptable columns. Laravel's default implementation reads from
     * `$this->attributes` directly which BYPASSES our `getAttribute()` override
     * — so without this override, `response()->json($model)` would leak the
     * raw ciphertext blob to the API client (and the UI would render it).
     *
     * Mutators (`get<Name>Attribute`) are picked up by Laravel automatically,
     * but our trait deliberately keeps the encryption opt-in via a single
     * `$encryptable` array so models don't need one accessor per column. Hence
     * the manual substitution below.
     */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        if (property_exists($this, 'encryptable') && is_array($this->encryptable)) {
            foreach ($this->encryptable as $col) {
                if (!array_key_exists($col, $attributes)) {
                    continue;
                }
                $raw = $attributes[$col];
                if (!is_string($raw) || $raw === '') {
                    continue;
                }
                $attributes[$col] = $this->decryptEncryptableValue($raw);
            }
        }

        return $attributes;
    }

    /**
     * Centralized decrypt-or-fail-closed helper. Used both by the
     * `getAttribute` override and the `attributesToArray` override so the
     * two code paths can never disagree.
     *
     * Defensive against legacy double-encryption: peels up to N layers as
     * long as each successive plaintext still looks like our Crypt envelope.
     */
    private function decryptEncryptableValue(string $value): ?string
    {
        $current = $value;

        for ($i = 0; $i < 5; $i++) {
            try {
                $next = Crypt::decryptString($current);
            } catch (\Throwable $e) {
                if ($this->looksLikeCryptEnvelope($current)) {
                    // Looks like our ciphertext but cannot be decrypted (key/MAC
                    // mismatch, truncation, double-encryption with a key we no
                    // longer have, …). Fail closed — never expose the blob.
                    return null;
                }
                // Legacy plaintext that was stored before encryption was
                // enabled, OR the layer we just unwrapped was the last one.
                return $current;
            }

            // Successfully peeled one layer. If what we got is itself a Crypt
            // envelope, peel another layer (handles legacy double-encryption).
            if ($this->looksLikeCryptEnvelope($next)) {
                $current = $next;
                continue;
            }

            return $next;
        }

        // Pathologically deep nesting — refuse to expose anything.
        return null;
    }

    private function looksLikeCryptEnvelope(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) && str_starts_with($decoded, '{"iv":');
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
