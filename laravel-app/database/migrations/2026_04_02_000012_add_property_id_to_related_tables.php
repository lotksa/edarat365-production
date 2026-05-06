<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('id');
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('id');
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });

        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('id');
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('id');
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });

        Schema::table('legal_cases', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('id');
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('id');
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $tables = ['facilities', 'contracts', 'maintenance_requests', 'meetings', 'legal_cases', 'approval_requests'];
        foreach ($tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropForeign(['property_id']);
                $table->dropColumn('property_id');
            });
        }
    }
};
