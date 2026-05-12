<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('property_owners')) {
            Schema::create('property_owners', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
                $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['property_id', 'owner_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_owners');
    }
};
