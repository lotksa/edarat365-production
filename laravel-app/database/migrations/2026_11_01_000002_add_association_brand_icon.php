<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('associations', 'icon')) {
            Schema::table('associations', function (Blueprint $table) {
                $table->string('icon')->nullable();
            });
        }
    }

    public function down(): void {}
};
