<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Generalises the `unit_images` table so it can also hold uploaded documents
 * (PDF, Word, Excel, …) alongside images. Strictly additive: no existing
 * column is dropped or renamed, so every row already in production is
 * preserved and continues to render exactly as before.
 *
 * - `kind`        : 'image' | 'document'  (defaults to 'image' for legacy rows)
 * - `mime_type`   : detected MIME at upload time, used by the UI to pick an
 *                   icon / preview strategy.
 * - `size_bytes`  : reported file size, useful for display and quota checks.
 *
 * The table name is kept as `unit_images` to avoid touching existing
 * relationships, foreign keys and live data; the application layer treats
 * each row as a generic "unit attachment".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('unit_images')) {
            return;
        }

        Schema::table('unit_images', function (Blueprint $table) {
            if (! Schema::hasColumn('unit_images', 'kind')) {
                $table->string('kind', 16)->default('image')->after('original_name');
            }
            if (! Schema::hasColumn('unit_images', 'mime_type')) {
                $table->string('mime_type', 128)->nullable()->after('kind');
            }
            if (! Schema::hasColumn('unit_images', 'size_bytes')) {
                $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
            }
        });

        // Backfill is a no-op for already-existing image rows (the default
        // already covers them). We still run a defensive UPDATE so any row
        // inserted between the schema change and the application restart
        // gets a sensible value instead of NULL.
        try {
            DB::table('unit_images')
                ->whereNull('kind')
                ->orWhere('kind', '')
                ->update(['kind' => 'image']);
        } catch (\Throwable $e) {
            // Don't abort the migration if the connection layer trips on
            // mixed types — the column default still protects new inserts.
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. PROJECT_RULES.md forbids dropping
        // user-data-bearing columns during automated deployments. To roll
        // this back manually, drop the columns by hand after taking a
        // backup of the unit_images table.
    }
};
