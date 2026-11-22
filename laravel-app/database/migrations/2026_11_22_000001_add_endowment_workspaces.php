<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['associations', 'activity_logs', 'notifications'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'section')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('section', 16)->default('owners')->index();
                });
            }
        }

        if (Schema::hasTable('roles') && ! DB::table('roles')->where('key', 'endowment_holder')->exists()) {
            DB::table('roles')->insert([
                'key' => 'endowment_holder',
                'name_ar' => 'صاحب وقف',
                'name_en' => 'Endowment holder',
                'description_ar' => 'الوصول إلى لوحة أصحاب الوقف المرتبط بالحساب',
                'description_en' => 'Access to the linked endowment portal',
                'color' => '#0891b2',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep endowment records and their account links on code rollback.
    }
};
