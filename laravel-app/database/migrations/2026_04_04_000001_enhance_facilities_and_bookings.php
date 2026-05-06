<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->foreignId('association_id')->nullable()->after('id')->constrained('associations')->cascadeOnDelete();
            $table->string('facility_type')->nullable()->after('name');
            $table->boolean('is_bookable')->default(false)->after('is_active');
            $table->integer('capacity')->nullable()->after('is_bookable');
            $table->decimal('hourly_rate', 10, 2)->default(0)->after('capacity');
            $table->string('location_detail')->nullable()->after('hourly_rate');
            $table->string('operating_hours_start', 5)->nullable()->after('location_detail');
            $table->string('operating_hours_end', 5)->nullable()->after('operating_hours_start');
            $table->json('images')->nullable()->after('operating_hours_end');
            $table->json('rules')->nullable()->after('images');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('association_id')->nullable()->after('id')->constrained('associations')->cascadeOnDelete();
            $table->string('booked_by')->default('owner')->after('status');
            $table->text('notes')->nullable()->after('booked_by');
            $table->dateTime('cancelled_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('association_id');
            $table->dropColumn(['facility_type', 'is_bookable', 'capacity', 'hourly_rate', 'location_detail', 'operating_hours_start', 'operating_hours_end', 'images', 'rules']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('association_id');
            $table->dropColumn(['booked_by', 'notes', 'cancelled_at']);
        });
    }
};
