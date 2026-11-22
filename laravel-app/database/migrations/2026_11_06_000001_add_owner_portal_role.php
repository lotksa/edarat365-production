<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add only the missing role. Existing roles, permissions and users stay intact.
        if (! DB::table('roles')->where('key', 'owner')->exists()) {
            DB::table('roles')->insert([
                'key' => 'owner', 'name_ar' => 'مالك', 'name_en' => 'Owner',
                'description_ar' => 'وصول إلى بوابة المالك وبياناته فقط',
                'description_en' => 'Access to the owner portal and own records only',
                'color' => '#0891b2', 'is_system' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve accounts and their role links on code rollback.
    }
};
