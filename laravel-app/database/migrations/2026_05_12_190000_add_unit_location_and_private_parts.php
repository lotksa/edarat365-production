<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (!Schema::hasColumn('units', 'site_city')) {
                $table->string('site_city')->nullable();
            }
            if (!Schema::hasColumn('units', 'site_district')) {
                $table->string('site_district')->nullable();
            }
            if (!Schema::hasColumn('units', 'site_plan_number')) {
                $table->string('site_plan_number')->nullable();
            }
            if (!Schema::hasColumn('units', 'site_plot_number')) {
                $table->string('site_plot_number')->nullable();
            }
            if (!Schema::hasColumn('units', 'building_permit_number')) {
                $table->string('building_permit_number')->nullable();
            }
            if (!Schema::hasColumn('units', 'building_permit_date')) {
                $table->date('building_permit_date')->nullable();
            }
            if (!Schema::hasColumn('units', 'street_name')) {
                $table->string('street_name')->nullable();
            }
            if (!Schema::hasColumn('units', 'street_width')) {
                $table->decimal('street_width', 8, 2)->nullable();
            }
            if (!Schema::hasColumn('units', 'land_area')) {
                $table->decimal('land_area', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('units', 'real_estate_number')) {
                $table->string('real_estate_number')->nullable();
            }
            if (!Schema::hasColumn('units', 'built_up_area')) {
                $table->decimal('built_up_area', 12, 2)->nullable();
            }
        });

        if (!Schema::hasTable('unit_private_parts')) {
            Schema::create('unit_private_parts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
                $table->string('name');
                $table->decimal('area', 12, 2)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
    }
};
