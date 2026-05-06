<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Votes ────────────────────────────────────────────────────
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->string('vote_number')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('association_id')->nullable()->constrained('associations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('total_voters')->default(0);
            $table->integer('quorum_percentage')->default(75);
            $table->string('status')->default('active');
            $table->integer('current_phase')->default(1);
            $table->timestamps();
        });

        // ── Vote Phases ──────────────────────────────────────────────
        Schema::create('vote_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vote_id')->constrained('votes')->cascadeOnDelete();
            $table->integer('phase_number');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('votes_yes')->default(0);
            $table->integer('votes_no')->default(0);
            $table->integer('votes_abstain')->default(0);
            $table->boolean('quorum_met')->default(false);
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        // ── Vote Responses ───────────────────────────────────────────
        Schema::create('vote_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vote_id')->constrained('votes')->cascadeOnDelete();
            $table->foreignId('vote_phase_id')->constrained('vote_phases')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->string('response');
            $table->timestamp('voted_at');
            $table->timestamps();

            $table->unique(['vote_id', 'owner_id']);
        });

        // ── Meetings: add invitation fields ──────────────────────────
        Schema::table('meetings', function (Blueprint $table) {
            $table->json('invitees')->nullable()->after('notes');
            $table->string('manager_name')->nullable()->after('invitees');
            $table->foreignId('manager_id')->nullable()->after('manager_name')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $cols = ['invitees', 'manager_name', 'manager_id'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('meetings', $c)) $table->dropColumn($c);
            }
        });

        Schema::dropIfExists('vote_responses');
        Schema::dropIfExists('vote_phases');
        Schema::dropIfExists('votes');
    }
};
