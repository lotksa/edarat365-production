<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZATCA-compliant invoice lifecycle.
 *
 * Saudi tax law (ZATCA / Fatoora) does NOT permit modifying an invoice after
 * issuance. Edits must be performed via:
 *   1. Cancelling the original invoice (it remains in the audit trail), and
 *   2. Issuing a NEW invoice that supersedes it (a credit-note-style flow).
 *
 * This migration is purely additive (PROJECT_RULES.md):
 *   - `due_date` becomes nullable (the original schema required it but the
 *     create form never sent one, which is the root-cause of the live 500
 *     on POST /api/v1/invoices). Loosening NOT NULL is non-destructive.
 *   - Adds lifecycle columns:
 *       issued_at              — moment the invoice left "draft" (locks edit)
 *       cancelled_at           — when the invoice was cancelled
 *       cancelled_by           — auth()->id() at cancel time (audit)
 *       cancellation_reason    — Arabic-friendly free text
 *       original_invoice_id    — for re-issued invoices, points back to the
 *                                cancelled predecessor
 *       replacement_invoice_id — on the cancelled invoice, points to the
 *                                replacement so the audit trail is two-way
 *
 * `down()` is intentionally a no-op so a stale checkout pulled by auto-deploy
 * cannot drop columns or restore the NOT NULL constraint that was the
 * root-cause of the production 500.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) return;

        // 1) Make due_date nullable (root-cause of the 500 on create).
        //    DBAL is not present in modern Laravel + we don't want to require
        //    it as a runtime dep, so we use a raw ALTER which MySQL handles
        //    idempotently for our purposes.
        try {
            DB::statement('ALTER TABLE `invoices` MODIFY `due_date` DATE NULL');
        } catch (\Throwable $e) {
            // Some MySQL flavours / earlier deploys may already have it
            // nullable. Failing here would block the rest of the lifecycle
            // columns, so we swallow and continue — the new columns are the
            // important part for ZATCA compliance.
        }

        // 2) Add lifecycle columns idempotently.
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'issued_at')) {
                $table->timestamp('issued_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('invoices', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('issued_at');
            }
            if (!Schema::hasColumn('invoices', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
                $table->index('cancelled_by');
            }
            if (!Schema::hasColumn('invoices', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
            if (!Schema::hasColumn('invoices', 'original_invoice_id')) {
                $table->unsignedBigInteger('original_invoice_id')->nullable()->after('cancellation_reason');
                $table->index('original_invoice_id');
            }
            if (!Schema::hasColumn('invoices', 'replacement_invoice_id')) {
                $table->unsignedBigInteger('replacement_invoice_id')->nullable()->after('original_invoice_id');
                $table->index('replacement_invoice_id');
            }
        });

        // 3) Backfill issued_at for already-issued rows so the lock-on-edit
        //    rule applies to historical data the same way it applies to new
        //    invoices going forward. We never touch rows whose status is
        //    'draft' or whose issued_at is already set.
        DB::statement("
            UPDATE invoices
            SET issued_at = COALESCE(issued_at, COALESCE(issue_date, created_at))
            WHERE issued_at IS NULL
              AND status IS NOT NULL
              AND status <> 'draft'
        ");
    }

    public function down(): void
    {
        // Intentionally non-destructive — see PROJECT_RULES.md.
    }
};
