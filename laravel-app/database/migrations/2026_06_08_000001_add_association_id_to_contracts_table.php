<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contracts') || Schema::hasColumn('contracts', 'association_id')) {
            return;
        }

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('association_id')
                ->nullable()
                ->after('property_id')
                ->constrained('associations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('contracts') || !Schema::hasColumn('contracts', 'association_id')) {
            return;
        }

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('association_id');
        });
    }
};
