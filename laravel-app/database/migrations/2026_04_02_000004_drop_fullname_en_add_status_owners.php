<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            if (Schema::hasColumn('owners', 'full_name_en')) {
                $table->dropColumn('full_name_en');
            }
            if (!Schema::hasColumn('owners', 'status')) {
                $table->string('status')->default('active')->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->string('full_name_en')->nullable();
            if (Schema::hasColumn('owners', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
