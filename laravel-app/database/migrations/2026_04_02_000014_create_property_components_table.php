<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('component_key', 100);
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();
            $table->unique(['property_id', 'component_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_components');
    }
};
