<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens PII columns to TEXT so the AES-256-CBC + HMAC ciphertext
 * (Base64 + JSON envelope, ~5x the plaintext size) fits safely.
 *
 * Affected columns (all become nullable TEXT):
 *   - owners: national_id, address_street, address_building_no,
 *             address_additional_no, address_postal_code, address_unit_no
 *   - tenants: same as owners
 *   - association_managers: national_id
 *   - property_managers: national_id
 *   - legal_representatives: license_number
 *   - contracts: party1_national_id, party2_national_id,
 *                party1_address, party2_address
 */
return new class extends Migration
{
    private const CHANGES = [
        'owners' => [
            'national_id', 'address_street', 'address_building_no',
            'address_additional_no', 'address_postal_code', 'address_unit_no',
        ],
        'tenants' => [
            'national_id', 'address_street', 'address_building_no',
            'address_additional_no', 'address_postal_code', 'address_unit_no',
        ],
        'association_managers' => ['national_id'],
        'property_managers'    => ['national_id'],
        'legal_representatives' => ['license_number'],
        'contracts' => [
            'party1_national_id', 'party2_national_id',
            'party1_address', 'party2_address',
        ],
    ];

    /**
     * Tables that need a `national_id_hash` blind-index column for fast
     * equality lookups on the encrypted value.
     */
    private const HASHED = [
        'owners'                => ['national_id' => 'national_id_hash'],
        'tenants'               => ['national_id' => 'national_id_hash'],
        'association_managers'  => ['national_id' => 'national_id_hash'],
        'property_managers'     => ['national_id' => 'national_id_hash'],
        'legal_representatives' => ['license_number' => 'license_number_hash'],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $table => $columns) {
            if (!Schema::hasTable($table)) continue;

            // Drop unique indexes that would conflict with TEXT columns and
            // are no longer meaningful once the value is encrypted (different
            // ciphertext per encryption call thanks to random IV).
            $this->dropUniqueIfExists($table, 'national_id');
            $this->dropUniqueIfExists($table, 'license_number');

            Schema::table($table, function (Blueprint $t) use ($columns, $table) {
                foreach ($columns as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->text($col)->nullable()->change();
                    }
                }
            });
        }

        // Add blind-index hash columns
        foreach (self::HASHED as $table => $cols) {
            if (!Schema::hasTable($table)) continue;
            foreach ($cols as $source => $hashCol) {
                if (!Schema::hasColumn($table, $hashCol)) {
                    Schema::table($table, function (Blueprint $t) use ($hashCol) {
                        $t->string($hashCol, 64)->nullable()->index();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        // No-op: shrinking these columns again is unsafe once encrypted data exists.
    }

    private function dropUniqueIfExists(string $table, string $column): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                $indexName = $table . '_' . $column . '_unique';
                $t->dropUnique($indexName);
            });
        } catch (\Throwable $e) {
            // Index didn't exist — ignore.
        }
    }
};
