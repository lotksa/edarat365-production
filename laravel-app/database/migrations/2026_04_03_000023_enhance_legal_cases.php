<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('verdict_status', 100)->nullable();
            $table->dateTime('reminder_date')->nullable();
            $table->text('details')->nullable();
            $table->json('documents')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('legal_cases', function (Blueprint $table) {
            if (!Schema::hasColumn('legal_cases', 'documents')) {
                $table->json('documents')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('legal_cases', 'court_type')) {
                $table->string('court_type', 100)->nullable()->after('court_name');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_updates');

        Schema::table('legal_cases', function (Blueprint $table) {
            if (Schema::hasColumn('legal_cases', 'documents')) {
                $table->dropColumn('documents');
            }
            if (Schema::hasColumn('legal_cases', 'court_type')) {
                $table->dropColumn('court_type');
            }
        });
    }
};
