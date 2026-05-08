<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detect rows whose encrypted PII columns are corrupted — i.e. the value
 * looks like our Crypt envelope (base64 of a JSON object starting with
 * `{"iv":`) but Crypt::decryptString() throws (key/MAC mismatch, truncated
 * ciphertext, double-encryption, etc.).
 *
 * For each such row, the corrupted column is set to NULL (and the matching
 * blind-index `_hash` column too) so the UI no longer renders ciphertext
 * gibberish to the user, and so the row is internally consistent for any
 * future re-entry of the data through the application.
 *
 * The command is idempotent and safe to run on every deploy. By default it
 * runs in --dry-run mode; pass --apply to write changes.
 *
 * Usage:
 *   php artisan pii:cleanup-corrupted              # dry-run (default)
 *   php artisan pii:cleanup-corrupted --apply
 *   php artisan pii:cleanup-corrupted --table=owners --apply
 */
class CleanupCorruptedPii extends Command
{
    protected $signature = 'pii:cleanup-corrupted
                            {--table= : Restrict to a single table}
                            {--apply : Actually write the cleanup (default is dry-run)}';

    protected $description = 'Null out encrypted PII rows that can no longer be decrypted (key/MAC mismatch).';

    /**
     * Mirror of EncryptLegacyPii::$map but without needing the model
     * classes — we only update the underlying tables here.
     *
     * @var array<string, array{columns: array<string>, hash?: array<string,string>}>
     */
    private array $map = [
        'owners' => [
            'columns' => ['national_id', 'address_street', 'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no'],
            'hash'    => ['national_id' => 'national_id_hash'],
        ],
        'tenants' => [
            'columns' => ['national_id', 'address_street', 'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no'],
            'hash'    => ['national_id' => 'national_id_hash'],
        ],
        'association_managers' => [
            'columns' => ['national_id'],
            'hash'    => ['national_id' => 'national_id_hash'],
        ],
        'property_managers' => [
            'columns' => ['national_id'],
            'hash'    => ['national_id' => 'national_id_hash'],
        ],
        'legal_representatives' => [
            'columns' => ['license_number'],
            'hash'    => ['license_number' => 'license_number_hash'],
        ],
        'contracts' => [
            'columns' => ['party1_national_id', 'party1_address', 'party2_national_id', 'party2_address'],
        ],
    ];

    public function handle(): int
    {
        $only  = $this->option('table');
        $apply = (bool) $this->option('apply');
        $totalCorrupted = 0;
        $totalCleaned   = 0;

        foreach ($this->map as $table => $cfg) {
            if ($only && $only !== $table) continue;

            if (!Schema::hasTable($table)) {
                $this->warn("→ {$table}: skipped (table missing)");
                continue;
            }

            $existing = array_values(array_filter($cfg['columns'], fn ($c) => Schema::hasColumn($table, $c)));
            if (empty($existing)) {
                $this->warn("→ {$table}: skipped (no encryptable columns)");
                continue;
            }

            $this->line("→ <comment>{$table}</comment>");
            [$found, $cleaned] = $this->processTable($table, $existing, $cfg['hash'] ?? [], $apply);
            $this->line("    corrupted={$found}  " . ($apply ? "cleaned={$cleaned}" : "would-clean={$cleaned}"));
            $totalCorrupted += $found;
            $totalCleaned   += $cleaned;
        }

        $verb = $apply ? 'cleaned' : 'would clean';
        $this->info("Total corrupted: {$totalCorrupted}  |  {$verb}: {$totalCleaned}");

        if (!$apply && $totalCorrupted > 0) {
            $this->warn('Re-run with --apply to actually null out the corrupted columns.');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int,string> $columns
     * @param array<string,string> $hashMap
     * @return array{int,int} [found, cleaned]
     */
    private function processTable(string $table, array $columns, array $hashMap, bool $apply): array
    {
        $found = 0;
        $cleaned = 0;

        DB::table($table)->orderBy('id')->select(array_merge(['id'], $columns))->chunkById(
            500,
            function ($rows) use ($table, $columns, $hashMap, $apply, &$found, &$cleaned) {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($columns as $col) {
                        $val = $row->{$col} ?? null;
                        if ($val === null || $val === '') continue;
                        if (!is_string($val)) continue;

                        if (!$this->looksLikeCryptEnvelope($val)) {
                            // Not our ciphertext — could be legacy plaintext. Leave it.
                            continue;
                        }

                        try {
                            Crypt::decryptString($val);
                            // Decrypts OK → valid ciphertext, leave it.
                            continue;
                        } catch (\Throwable $e) {
                            // Looks like our envelope but cannot be decrypted.
                            $found++;
                            $updates[$col] = null;
                            if (isset($hashMap[$col])) {
                                $updates[$hashMap[$col]] = null;
                            }
                        }
                    }

                    if (!empty($updates)) {
                        $cleaned++;
                        if ($apply) {
                            DB::table($table)->where('id', $row->id)->update($updates);
                        }
                    }
                }
            },
            'id'
        );

        return [$found, $cleaned];
    }

    /**
     * Laravel's Crypt::encryptString() output is base64 of a JSON object
     * whose first key is `"iv"`. We use that as a structural fingerprint
     * to distinguish "our ciphertext" from arbitrary strings.
     */
    private function looksLikeCryptEnvelope(string $value): bool
    {
        $decoded = base64_decode($value, true);
        return is_string($decoded) && str_starts_with($decoded, '{"iv":');
    }
}
