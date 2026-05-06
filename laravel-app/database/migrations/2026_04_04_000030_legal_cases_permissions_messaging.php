<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_representatives', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('specialty', 100)->nullable();
            $table->string('license_number', 100)->nullable();
            $table->string('firm_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('case_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('representative_id')->nullable()->constrained('legal_representatives')->nullOnDelete();
            $table->string('role', 50)->default('viewer');
            $table->json('permissions')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['legal_case_id', 'user_id'], 'cp_case_user_unique');
            $table->unique(['legal_case_id', 'representative_id'], 'cp_case_rep_unique');
        });

        Schema::create('case_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reply_to_id')->nullable()->constrained('case_messages')->nullOnDelete();
            $table->text('content')->nullable();
            $table->string('type', 30)->default('text');
            $table->json('attachments')->nullable();
            $table->json('read_by')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->index(['legal_case_id', 'created_at']);
        });

        Schema::create('case_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('remind_at');
            $table->string('status', 30)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_reminders');
        Schema::dropIfExists('case_messages');
        Schema::dropIfExists('case_permissions');
        Schema::dropIfExists('legal_representatives');
    }
};
