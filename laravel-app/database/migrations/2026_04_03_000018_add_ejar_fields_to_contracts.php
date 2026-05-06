<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('contract_number')->nullable()->after('id');
            $table->string('contract_type')->default('residential')->after('contract_number');
            $table->string('utilities_responsibility')->default('tenant')->after('notes');
            $table->boolean('insurance_required')->default(false)->after('utilities_responsibility');
            $table->string('maintenance_responsibility')->default('shared')->after('insurance_required');
            $table->json('contract_clauses')->nullable()->after('maintenance_responsibility');
            $table->string('ejar_reference_id')->nullable()->after('contract_clauses');
            $table->string('ejar_status')->nullable()->after('ejar_reference_id');
            $table->timestamp('ejar_synced_at')->nullable()->after('ejar_status');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'contract_number', 'contract_type',
                'utilities_responsibility', 'insurance_required', 'maintenance_responsibility',
                'contract_clauses', 'ejar_reference_id', 'ejar_status', 'ejar_synced_at',
            ]);
        });
    }
};
