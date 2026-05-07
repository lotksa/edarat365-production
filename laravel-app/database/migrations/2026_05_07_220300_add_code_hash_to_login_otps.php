<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop storing OTP codes in plaintext.
 *
 * Adds a `code_hash` column (HMAC-SHA256 of the OTP using APP_KEY as the
 * secret). The legacy `code` column is kept nullable for the duration of
 * the rollout, but the application no longer reads or writes to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('login_otps')) return;

        Schema::table('login_otps', function (Blueprint $t) {
            if (!Schema::hasColumn('login_otps', 'code_hash')) {
                $t->string('code_hash', 64)->nullable()->after('code')->index();
            }
            if (Schema::hasColumn('login_otps', 'code')) {
                $t->string('code', 8)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('login_otps', function (Blueprint $t) {
            if (Schema::hasColumn('login_otps', 'code_hash')) $t->dropColumn('code_hash');
        });
    }
};
