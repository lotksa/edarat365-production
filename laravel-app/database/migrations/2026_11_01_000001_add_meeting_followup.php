<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invitation_date', 'meeting_call', 'previous_meeting_id', 'documented_at', 'minutes_signed_at', 'quorum_snapshot', 'meeting_format'] as $column) {
            if (! Schema::hasColumn('meetings', $column)) {
                Schema::table('meetings', function (Blueprint $table) use ($column) {
                    match ($column) {
                        'invitation_date' => $table->date($column)->nullable(),
                        'meeting_call' => $table->unsignedTinyInteger($column)->default(1),
                        'previous_meeting_id' => $table->unsignedBigInteger($column)->nullable()->index(),
                        'quorum_snapshot' => $table->json($column)->nullable(),
                        'meeting_format' => $table->string($column, 20)->nullable(),
                        default => $table->timestamp($column)->nullable(),
                    };
                });
            }
        }
        if (! Schema::hasTable('meeting_followup_reminders')) {
            Schema::create('meeting_followup_reminders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('association_id')->index();
                $table->unsignedBigInteger('meeting_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedSmallInteger('year');
                $table->string('channel', 20);
                $table->json('recipients');
                $table->text('message');
                $table->string('status', 30)->default('recorded');
                $table->string('deduplication_key')->nullable()->unique();
                $table->timestamps();
            });
        }
    }

    // This additive deployment migration deliberately preserves records on rollback.
    public function down(): void {}
};
