<?php

namespace App\Console\Commands;

use App\Models\AssociationManager;
use App\Models\Concerns\EncryptsAttributes;
use App\Models\Contract;
use App\Models\LegalRepresentative;
use App\Models\Owner;
use App\Models\PropertyManager;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-encrypts plaintext PII rows that were stored before encryption was
 * enabled. Detection: ciphertext from Crypt::encryptString() is a base64
 * encoding of a JSON envelope ({"iv":..,"value":..,"mac":..,"tag":..}). If
 * decryption succeeds, the column is already encrypted and is skipped.
 *
 * Usage:
 *   php artisan encrypt:legacy-pii            # all tables
 *   php artisan encrypt:legacy-pii --table=owners
 *   php artisan encrypt:legacy-pii --dry-run
 */
class EncryptLegacyPii extends Command
{
    protected $signature = 'encrypt:legacy-pii
                            {--table= : Restrict to a single table}
                            {--dry-run : Report counts without writing}';

    protected $description = 'Encrypt PII columns that were stored as plaintext before encryption was enabled.';

    /**
     * @var array<class-string<Model>, array{table:string, columns:array<string>, hash?:array<string,string>}>
     */
    private array $map;

    public function __construct()
    {
        parent::__construct();

        $this->map = [
            Owner::class => [
                'table'   => 'owners',
                'columns' => ['national_id', 'address_street', 'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no'],
                'hash'    => ['national_id' => 'national_id_hash'],
            ],
            Tenant::class => [
                'table'   => 'tenants',
                'columns' => ['national_id', 'address_street', 'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no'],
                'hash'    => ['national_id' => 'national_id_hash'],
            ],
            AssociationManager::class => [
                'table'   => 'association_managers',
                'columns' => ['national_id'],
                'hash'    => ['national_id' => 'national_id_hash'],
            ],
            PropertyManager::class => [
                'table'   => 'property_managers',
                'columns' => ['national_id'],
                'hash'    => ['national_id' => 'national_id_hash'],
            ],
            LegalRepresentative::class => [
                'table'   => 'legal_representatives',
                'columns' => ['license_number'],
                'hash'    => ['license_number' => 'license_number_hash'],
            ],
            Contract::class => [
                'table'   => 'contracts',
                'columns' => ['party1_national_id', 'party1_address', 'party2_national_id', 'party2_address'],
            ],
        ];
    }

    private function blindHash(string $value): string
    {
        $secret = config('app.key');
        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7));
        }
        return hash_hmac('sha256', trim(mb_strtolower($value)), $secret . '|blind_index');
    }

    public function handle(): int
    {
        $only = $this->option('table');
        $dry = (bool) $this->option('dry-run');
        $totalEncrypted = 0;
        $totalSkipped = 0;

        foreach ($this->map as $modelClass => $cfg) {
            if ($only && $only !== $cfg['table']) continue;

            $this->line("→ <comment>{$cfg['table']}</comment>");
            [$enc, $skip] = $this->processTable($modelClass, $cfg, $dry);
            $this->line("    encrypted={$enc}  skipped(already-encrypted)={$skip}");
            $totalEncrypted += $enc;
            $totalSkipped   += $skip;
        }

        $verb = $dry ? 'would encrypt' : 'encrypted';
        $this->info("Total {$verb}: {$totalEncrypted} rows. Already-encrypted: {$totalSkipped}.");

        return self::SUCCESS;
    }

    /**
     * @param class-string<Model> $modelClass
     * @return array{int,int} [encrypted, skipped]
     */
    private function processTable(string $modelClass, array $cfg, bool $dryRun): array
    {
        $table   = $cfg['table'];
        $columns = $cfg['columns'];
        $hashMap = $cfg['hash'] ?? [];

        if (!Schema::hasTable($table)) {
            $this->warn("    skipped — table missing");
            return [0, 0];
        }

        // Only operate on columns that exist (some columns may not exist on legacy schemas).
        $existing = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
        if (empty($existing)) {
            $this->warn("    skipped — no encryptable columns found in schema");
            return [0, 0];
        }

        $encrypted = 0;
        $skipped = 0;
        $pageSize = 200;

        // Stream through rows so we don't load the whole table.
        DB::table($table)->orderBy('id')->select(array_merge(['id'], $existing))->chunkById(
            $pageSize,
            function ($rows) use (&$encrypted, &$skipped, $table, $existing, $hashMap, $dryRun) {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($existing as $col) {
                        $val = $row->{$col} ?? null;
                        if ($val === null || $val === '') continue;

                        if ($this->looksEncrypted((string) $val)) {
                            // Already encrypted — but still backfill hash if missing & we know plaintext source.
                            // We can't recover plaintext from already-encrypted values without decrypting,
                            // so re-decrypt to compute the hash if a mapping is configured.
                            if (isset($hashMap[$col])) {
                                try {
                                    $plain = Crypt::decryptString((string) $val);
                                    $updates[$hashMap[$col]] = $this->blindHash($plain);
                                } catch (\Throwable $e) {
                                    // ignore
                                }
                            }
                            $skipped++;
                            continue;
                        }

                        $updates[$col] = Crypt::encryptString((string) $val);
                        if (isset($hashMap[$col])) {
                            $updates[$hashMap[$col]] = $this->blindHash((string) $val);
                        }
                        $encrypted++;
                    }

                    if (!empty($updates) && !$dryRun) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            },
            'id'
        );

        return [$encrypted, $skipped];
    }

    /**
     * Quick heuristic: try to decrypt; if it succeeds we already had ciphertext.
     */
    private function looksEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
