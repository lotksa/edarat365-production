<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident security audit log for sensitive events.
 *
 * Events tracked:
 *   - auth.login.success / auth.login.failed
 *   - auth.otp.requested / auth.otp.verified / auth.otp.failed
 *   - auth.lockout / auth.unlock
 *   - auth.password.changed / auth.password.reset
 *   - rbac.role.assigned / rbac.permission.changed
 *   - data.pii.exported / data.pii.deleted
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_audit_log')) return;

        Schema::create('security_audit_log', function (Blueprint $t) {
            $t->id();
            $t->string('event', 64)->index();
            $t->unsignedBigInteger('actor_user_id')->nullable()->index();
            $t->string('actor_identifier', 255)->nullable();
            $t->string('subject_type', 64)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->json('context')->nullable();
            $t->string('outcome', 16)->default('success');
            $t->timestamp('created_at')->useCurrent();

            $t->index(['subject_type', 'subject_id']);
            $t->index(['event', 'outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audit_log');
    }
};
