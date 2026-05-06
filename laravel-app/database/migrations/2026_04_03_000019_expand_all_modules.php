<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Meetings: expand existing table ─────────────────────────
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('meeting_number')->nullable()->after('id');
            $table->foreignId('association_id')->nullable()->after('property_id')->constrained('associations')->nullOnDelete();
            $table->string('location')->nullable()->after('agenda');
            $table->text('minutes')->nullable()->after('location');
            $table->string('status')->default('scheduled')->after('minutes');
            $table->text('notes')->nullable()->after('status');
        });

        // ── Resolutions: expand existing table ──────────────────────
        Schema::table('resolutions', function (Blueprint $table) {
            $table->string('resolution_number')->nullable()->after('id');
            $table->string('resolution_type')->nullable()->after('title');
            $table->string('status')->default('pending')->after('abstain_votes');
        });

        // ── Legal Cases: expand existing table ──────────────────────
        Schema::table('legal_cases', function (Blueprint $table) {
            $table->foreignId('association_id')->nullable()->after('property_id')->constrained('associations')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('association_id')->constrained('owners')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->after('owner_id')->constrained('units')->nullOnDelete();
            $table->string('case_type')->nullable()->after('title');
            $table->string('court_name')->nullable()->after('case_type');
            $table->string('plaintiff')->nullable()->after('court_name');
            $table->string('defendant')->nullable()->after('plaintiff');
            $table->string('lawyer_name')->nullable()->after('defendant');
            $table->date('filing_date')->nullable()->after('lawyer_name');
            $table->string('priority')->default('medium')->after('hearing_date');
            $table->text('description')->nullable()->after('priority');
            $table->text('verdict')->nullable()->after('description');
            $table->decimal('amount', 12, 2)->nullable()->after('verdict');
            $table->text('notes')->nullable()->after('amount');
        });

        // ── Invoices: expand existing table ──────────────────────────
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('id');
            $table->string('invoice_type')->nullable()->after('invoice_number');
            $table->foreignId('association_id')->nullable()->after('invoice_type')->constrained('associations')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->after('association_id')->constrained('properties')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->after('unit_id')->constrained('tenants')->nullOnDelete();
            $table->date('issue_date')->nullable()->after('due_date');
            $table->decimal('tax_amount', 10, 2)->default(0)->after('amount');
            $table->decimal('total_amount', 12, 2)->nullable()->after('tax_amount');
            $table->string('payment_method')->nullable()->after('status');
            $table->text('description')->nullable()->after('payment_method');
            $table->json('line_items')->nullable()->after('description');
            $table->text('notes')->nullable()->after('line_items');
        });

        // ── Transactions (الحركات المالية) ── New table ─────────────
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number')->nullable();
            $table->string('transaction_type'); // income, expense, transfer
            $table->string('category')->nullable();
            $table->foreignId('association_id')->nullable()->constrained('associations')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method')->nullable();
            $table->date('transaction_date');
            $table->string('reference_number')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('completed');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');

        Schema::table('invoices', function (Blueprint $table) {
            $cols = ['invoice_number','invoice_type','association_id','property_id','tenant_id',
                     'issue_date','tax_amount','total_amount','payment_method','description','line_items','notes'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('invoices', $c)) $table->dropColumn($c);
            }
        });

        Schema::table('legal_cases', function (Blueprint $table) {
            $cols = ['association_id','owner_id','unit_id','case_type','court_name','plaintiff',
                     'defendant','lawyer_name','filing_date','priority','description','verdict','amount','notes'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('legal_cases', $c)) $table->dropColumn($c);
            }
        });

        Schema::table('resolutions', function (Blueprint $table) {
            $cols = ['resolution_number','resolution_type','status'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('resolutions', $c)) $table->dropColumn($c);
            }
        });

        Schema::table('meetings', function (Blueprint $table) {
            $cols = ['meeting_number','association_id','location','minutes','status','notes'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('meetings', $c)) $table->dropColumn($c);
            }
        });
    }
};
