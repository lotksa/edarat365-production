<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_otps', function (Blueprint $table) {
            $table->string('code', 6)->change();
        });

        if (!Schema::hasColumn('login_otps', 'channel')) {
            Schema::table('login_otps', function (Blueprint $table) {
                $table->string('channel', 16)->default('phone')->after('identifier');
            });
        }

        if (!Schema::hasColumn('login_otps', 'purpose')) {
            Schema::table('login_otps', function (Blueprint $table) {
                $table->string('purpose', 32)->default('login')->after('channel');
            });
        }

        if (!Schema::hasColumn('login_otps', 'attempts')) {
            Schema::table('login_otps', function (Blueprint $table) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('used_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('login_otps', function (Blueprint $table) {
            if (Schema::hasColumn('login_otps', 'attempts')) {
                $table->dropColumn('attempts');
            }
            if (Schema::hasColumn('login_otps', 'purpose')) {
                $table->dropColumn('purpose');
            }
            if (Schema::hasColumn('login_otps', 'channel')) {
                $table->dropColumn('channel');
            }
            $table->string('code', 4)->change();
        });
    }
};
