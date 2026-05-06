<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->foreignId('association_id')->nullable()->after('id')->constrained('associations')->nullOnDelete();
            $table->string('type', 100)->nullable()->after('title');
            $table->string('category', 100)->nullable()->after('type');
            $table->string('location', 255)->nullable()->after('description');
            $table->string('assigned_to', 255)->nullable()->after('status');
            $table->string('assigned_phone', 50)->nullable()->after('assigned_to');
            $table->decimal('estimated_cost', 12, 2)->nullable()->after('assigned_phone');
            $table->decimal('actual_cost', 12, 2)->nullable()->after('estimated_cost');
            $table->date('scheduled_date')->nullable()->after('actual_cost');
            $table->date('completed_date')->nullable()->after('scheduled_date');
            $table->text('resolution_notes')->nullable()->after('completed_date');
            $table->json('images')->nullable()->after('resolution_notes');
            $table->unsignedTinyInteger('rating')->nullable()->after('images');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropForeign(['association_id']);
            $table->dropColumn([
                'association_id', 'type', 'category', 'location',
                'assigned_to', 'assigned_phone', 'estimated_cost', 'actual_cost',
                'scheduled_date', 'completed_date', 'resolution_notes', 'images', 'rating',
            ]);
        });
    }
};
