<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            if (!Schema::hasColumn('meetings', 'attendees')) {
                $table->json('attendees')->nullable()->after('invitees');
            }
        });

        Schema::table('votes', function (Blueprint $table) {
            if (!Schema::hasColumn('votes', 'meeting_id')) {
                $table->foreignId('meeting_id')->nullable()->after('association_id')
                      ->constrained('meetings')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            if (Schema::hasColumn('votes', 'meeting_id')) {
                $table->dropForeign(['meeting_id']);
                $table->dropColumn('meeting_id');
            }
        });

        Schema::table('meetings', function (Blueprint $table) {
            if (Schema::hasColumn('meetings', 'attendees')) {
                $table->dropColumn('attendees');
            }
        });
    }
};
