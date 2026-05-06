<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_managers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('national_id')->unique();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::table('associations', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
            $table->string('association_number')->nullable()->after('registration_number');
            $table->date('first_approval_date')->nullable()->after('established_date');
            $table->date('expiry_date')->nullable()->after('first_approval_date');
            $table->string('unified_number')->nullable()->after('expiry_date');
            $table->string('establishment_number')->nullable()->after('unified_number');
            $table->string('management_model')->nullable()->after('status');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->foreignId('manager_id')->nullable()->constrained('association_managers')->nullOnDelete();
            $table->date('manager_start_date')->nullable();
            $table->date('manager_end_date')->nullable();
            $table->decimal('manager_salary', 10, 2)->nullable();
            $table->boolean('has_commission')->default(false);
            $table->string('commission_type')->nullable();
            $table->decimal('commission_value', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('associations', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn([
                'name_en', 'association_number', 'first_approval_date', 'expiry_date',
                'unified_number', 'establishment_number', 'management_model',
                'latitude', 'longitude', 'manager_id', 'manager_start_date',
                'manager_end_date', 'manager_salary', 'has_commission',
                'commission_type', 'commission_value',
            ]);
        });
        Schema::dropIfExists('association_managers');
    }
};
