<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('associations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('registration_number')->unique()->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('manager_name')->nullable();
            $table->date('established_date')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('property_number')->unique()->nullable();
            $table->string('name');
            $table->string('type')->default('residential');
            $table->foreignId('association_id')->nullable()->constrained('associations')->nullOnDelete();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->integer('total_units')->default(0);
            $table->integer('total_floors')->default(0);
            $table->year('year_built')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete()->after('id');
            $table->string('unit_type')->default('apartment')->after('unit_number');
            $table->integer('floor_number')->nullable()->after('unit_type');
            $table->decimal('area', 8, 2)->nullable()->after('floor_number');
            $table->integer('bedrooms')->nullable()->after('area');
            $table->integer('bathrooms')->nullable()->after('bedrooms');
            $table->string('status')->default('vacant')->after('owner_id');
            $table->decimal('monthly_rent', 10, 2)->nullable()->after('status');
            $table->text('notes')->nullable()->after('monthly_rent');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropColumn(['property_id', 'unit_type', 'floor_number', 'area', 'bedrooms', 'bathrooms', 'status', 'monthly_rent', 'notes']);
        });
        Schema::dropIfExists('properties');
        Schema::dropIfExists('associations');
    }
};
