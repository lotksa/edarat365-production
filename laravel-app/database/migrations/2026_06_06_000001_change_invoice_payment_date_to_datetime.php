<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'payment_date')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE invoices MODIFY payment_date DATETIME NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'payment_date')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE invoices MODIFY payment_date DATE NULL');
            }
        }
    }
};
