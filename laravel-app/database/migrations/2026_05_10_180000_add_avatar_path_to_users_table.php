<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `avatar_path` column to `users` so admin users can upload a
 * profile picture stored on the public disk (separate from `avatar_url`
 * which historically held an external URL).
 *
 * Per PROJECT_RULES.md this migration is purely additive — `down()` is a
 * no-op so re-running it on a deploy that pulls a stale checkout cannot
 * destroy production data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) return;
        if (!Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar_path', 512)->nullable()->after('avatar_url');
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive — see PROJECT_RULES.md.
    }
};
