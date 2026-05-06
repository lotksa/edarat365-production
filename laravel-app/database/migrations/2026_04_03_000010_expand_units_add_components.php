<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('unit_code')->nullable()->after('id');
            $table->string('description')->nullable()->after('unit_type');
            $table->string('deed_number')->nullable()->after('area');
            $table->string('deed_source')->nullable()->after('deed_number');
            $table->decimal('percentage', 8, 4)->nullable()->after('ownership_ratio');
            $table->string('furnished')->nullable()->after('bathrooms');
        });

        Schema::create('unit_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->string('component_key');
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['unit_id', 'component_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_components');
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['unit_code', 'description', 'deed_number', 'deed_source', 'percentage', 'furnished']);
        });
    }
};
