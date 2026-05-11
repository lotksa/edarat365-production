<?php

namespace App\Rules;

use App\Models\Concerns\EncryptsAttributes;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // Soft-delete awareness. The original implementation wrapped a bare
        // `whereNull('deleted_at')` in try/catch, but `whereNull()` only
        // BUILDS the query — the SQL exception fires later inside `exists()`
        // and was therefore never caught, causing a 500 on every table that
        // doesn't have a `deleted_at` column (association_managers,
        // property_managers, …). Check the schema up-front instead.
        if ($this->ignoreSoftDeleted) {
            try {
                if (Schema::hasColumn($this->table, 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
            } catch (\Throwable $e) {
                // hasColumn shouldn't throw in practice but defend anyway —
                // never let a uniqueness check escalate into a 500.
            }
        }

        try {
            if ($q->exists()) {
                $fail("This :attribute is already in use.");
            }
        } catch (\Throwable $e) {
            // Belt-and-suspenders: a misconfigured table/column should never
            // crash the create flow; fail open (no uniqueness clash) and let
            // the database's own constraints reject true duplicates.
            \Illuminate\Support\Facades\Log::warning('UniqueEncrypted check failed on '
                . $this->table . '.' . $this->hashColumn . ': ' . $e->getMessage());
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
