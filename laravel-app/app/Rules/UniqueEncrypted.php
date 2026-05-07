<?php

namespace App\Rules;

use App\Models\Concerns\EncryptsAttributes;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Uniqueness check for encrypted columns via the matching `_hash` (blind index).
 *
 * Usage:
 *   'national_id' => ['required', new UniqueEncrypted('owners', 'national_id_hash')],
 *
 * Or with an "ignore-this-id" exclusion (update flow):
 *   new UniqueEncrypted('owners', 'national_id_hash', ignoreId: $owner->id)
 */
class UniqueEncrypted implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $hashColumn,
        private readonly ?int $ignoreId = null,
        private readonly string $idColumn = 'id',
        private readonly bool $ignoreSoftDeleted = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') return;

        $hash = self::hash((string) $value);

        $q = DB::table($this->table)->where($this->hashColumn, $hash);
        if ($this->ignoreId !== null) {
            $q->where($this->idColumn, '!=', $this->ignoreId);
        }
        if ($this->ignoreSoftDeleted) {
            // Best-effort soft-delete awareness.
            try {
                $q->whereNull('deleted_at');
            } catch (\Throwable $e) {
                // table without soft deletes — ignore
            }
        }

        if ($q->exists()) {
            $fail("This :attribute is already in use.");
        }
    }

    public static function hash(string $value): string
    {
        // Re-uses the same blind-index recipe as EncryptsAttributes
        $secret = config('app.key');
        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7));
        }
        return hash_hmac('sha256', trim(mb_strtolower($value)), $secret . '|blind_index');
    }
}
