<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `performer_id` column to `activity_logs` so the per-user activity
 * tab can list every action a given user performed across the platform
 * (creating units, approving requests, deleting owners, etc.) regardless
 * of which model the action targeted.
 *
 * The existing `performer` (string) column is left intact for backward
 * compatibility — newly-written rows store both the human-readable name
 * and the numeric user id when one is available.
 *
 * Per PROJECT_RULES.md this migration is purely additive. `down()` is a
 * no-op so an accidental rollback from auto-deploy cannot wipe the new
 * column on production.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('activity_logs')) return;
        if (!Schema::hasColumn('activity_logs', 'performer_id')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('performer_id')->nullable()->after('performer');
                $table->index('performer_id');
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive — see PROJECT_RULES.md.
    }
};
