<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account lockout columns to defeat brute-force / credential-stuffing attacks.
 *   - failed_login_attempts : counter, reset on success
 *   - locked_until          : NULL or datetime when account is unlocked
 *   - last_failed_login_at  : last failed timestamp (audit)
 *   - last_login_ip         : last successful login IP (audit)
 *   - password_changed_at   : audit of password rotation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'failed_login_attempts')) {
                $t->unsignedSmallInteger('failed_login_attempts')->default(0)->after('is_active');
            }
            if (!Schema::hasColumn('users', 'locked_until')) {
                $t->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            }
            if (!Schema::hasColumn('users', 'last_failed_login_at')) {
                $t->timestamp('last_failed_login_at')->nullable()->after('locked_until');
            }
            if (!Schema::hasColumn('users', 'last_login_ip')) {
                $t->string('last_login_ip', 45)->nullable()->after('last_login_at');
            }
            if (!Schema::hasColumn('users', 'password_changed_at')) {
                $t->timestamp('password_changed_at')->nullable()->after('last_login_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            foreach (['failed_login_attempts','locked_until','last_failed_login_at','last_login_ip','password_changed_at'] as $col) {
                if (Schema::hasColumn('users', $col)) $t->dropColumn($col);
            }
        });
    }
};
