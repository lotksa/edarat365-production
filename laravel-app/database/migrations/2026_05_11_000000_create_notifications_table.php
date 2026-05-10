<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real-time notifications table.
 *
 * One row per recipient. The Notifier service creates one row per user when
 * a broadcast notification fires (admin audience → fan-out to all matching
 * users); for owner notifications the row is keyed by `owner_id` instead so
 * we can show the same dropdown UX to owner accounts that may not yet have
 * a corresponding `users` row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // Domain identifiers
            $table->string('event_key', 64)->index();    // e.g. invoice.created, maintenance.status_changed
            $table->string('module', 32)->index();       // invoices, maintenance, meetings, ...
            $table->string('audience', 16)->default('admin'); // admin | owner | user

            // Recipient: exactly one of these is populated.
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('owner_id')->nullable()->index();

            // Content (bilingual — kept in row so we don't have to re-render
            // when the locale changes on the frontend).
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->string('level', 16)->default('info'); // info | success | warning | danger

            // Polymorphic link back to the source row so the UI can deep-link.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Deep link (frontend route) — pre-computed at notify-time so the
            // UI never has to map subject types back to routes.
            $table->string('link', 255)->nullable();

            // Extra context (status, amount, etc.) for richer rendering.
            $table->json('data')->nullable();

            // Read tracking
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Hot path: fetching unread for current user.
            $table->index(['user_id', 'read_at']);
            $table->index(['owner_id', 'read_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
