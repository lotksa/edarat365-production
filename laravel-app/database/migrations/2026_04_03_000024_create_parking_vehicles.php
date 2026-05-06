<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_spots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('parking_type', 100)->nullable();
            $table->string('parking_number', 100);
            $table->string('status', 50)->default('active');
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parking_spot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('parking_type', 100)->nullable();
            $table->string('car_type', 100)->nullable();
            $table->string('car_model', 100)->nullable();
            $table->string('car_color', 100)->nullable();
            $table->string('plate_number', 100)->nullable();
            $table->string('driver_name', 255)->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('parking_spots');
    }
};
