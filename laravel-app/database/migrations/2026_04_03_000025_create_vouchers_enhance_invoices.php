<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('invoices', 'vat_rate')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('vat_rate', 5, 2)->nullable()->after('tax_amount');
                $table->decimal('discount_amount', 12, 2)->default(0)->after('vat_rate');
                $table->date('payment_date')->nullable()->after('issue_date');
            });
        }

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_number', 50)->unique();
            $table->enum('type', ['receipt', 'payment'])->default('receipt');
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->string('payment_method', 100)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 50)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');

        if (Schema::hasColumn('invoices', 'vat_rate')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn(['vat_rate', 'discount_amount', 'payment_date']);
            });
        }
    }
};
