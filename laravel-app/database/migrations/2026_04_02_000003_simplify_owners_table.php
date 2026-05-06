<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $columns = ['association_name', 'ownership_percentage', 'mullak_status', 'city', 'status', 'address', 'notes'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('owners', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->string('association_name')->nullable();
            $table->decimal('ownership_percentage', 5, 2)->nullable();
            $table->string('mullak_status')->default('unregistered');
            $table->string('city')->nullable();
            $table->string('status')->default('active');
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
        });
    }
};
