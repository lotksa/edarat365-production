<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->json('agenda_items')->nullable()->after('agenda');
            $table->string('attendance_type')->nullable()->after('notes');
            $table->unsignedBigInteger('attendance_scope_id')->nullable()->after('attendance_type');
            $table->boolean('is_remote')->default(false)->after('attendance_scope_id');
            $table->string('remote_platform')->nullable()->after('is_remote');
            $table->string('remote_link')->nullable()->after('remote_platform');
        });

        if (! Schema::hasColumn('meetings', 'manager_name')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->string('manager_name')->nullable()->after('remote_link');
            });
        }

        if (! Schema::hasColumn('meetings', 'invitees')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->json('invitees')->nullable()->after('manager_name');
            });
        }

        if (! Schema::hasColumn('meetings', 'manager_id')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->foreignId('manager_id')->nullable()->after('invitees')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $cols = ['agenda_items', 'attendance_type', 'attendance_scope_id', 'is_remote', 'remote_platform', 'remote_link'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('meetings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
