<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('national_id')->unique();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('nationality')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('owner_id')->constrained('tenants')->nullOnDelete();
            $table->string('payment_type')->nullable()->after('end_date');
            $table->string('contract_period')->nullable()->after('payment_type');
            $table->decimal('rental_amount', 10, 2)->nullable()->after('contract_period');
            $table->text('notes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'payment_type', 'contract_period', 'rental_amount', 'notes']);
        });

        Schema::dropIfExists('tenants');
    }
};
