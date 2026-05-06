<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_managers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->string('national_id', 10)->unique();
            $table->string('phone', 10)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'plot_number')) {
                $table->string('plot_number')->nullable();
            }
            if (!Schema::hasColumn('properties', 'area')) {
                $table->decimal('area', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('properties', 'green_area')) {
                $table->decimal('green_area', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('properties', 'deed_number')) {
                $table->string('deed_number')->nullable();
            }
            if (!Schema::hasColumn('properties', 'deed_source')) {
                $table->string('deed_source')->nullable();
            }
            if (!Schema::hasColumn('properties', 'building_type')) {
                $table->string('building_type')->nullable();
            }
            if (!Schema::hasColumn('properties', 'total_elevators')) {
                $table->integer('total_elevators')->default(0);
            }
            if (!Schema::hasColumn('properties', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }
            if (!Schema::hasColumn('properties', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }
            if (!Schema::hasColumn('properties', 'property_manager_id')) {
                $table->foreignId('property_manager_id')->nullable()->constrained('property_managers')->nullOnDelete();
            }
        });

        Schema::create('utility_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('meter_type');
            $table->string('meter_number');
            $table->string('account_number')->nullable();
            $table->string('account_type')->nullable();
            $table->timestamps();
        });

        Schema::create('property_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('doc_name');
            $table->string('doc_type')->nullable();
            $table->string('file_path');
            $table->integer('file_size')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_documents');
        Schema::dropIfExists('utility_meters');

        Schema::table('properties', function (Blueprint $table) {
            $columns = [
                'plot_number', 'area', 'green_area', 'deed_number', 'deed_source',
                'building_type', 'total_elevators', 'latitude', 'longitude', 'property_manager_id',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('properties', $col)) {
                    if ($col === 'property_manager_id') {
                        $table->dropForeign(['property_manager_id']);
                    }
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('property_managers');
    }
};
