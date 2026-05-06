<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('contract_name')->nullable()->after('contract_number');
            $table->string('contract_nature')->nullable()->after('contract_name');
            $table->date('contract_date')->nullable()->after('contract_nature');
            $table->string('venue')->nullable()->after('contract_date');
            $table->string('venue_address')->nullable()->after('venue');
            $table->string('venue_city')->nullable()->after('venue_address');

            // Party 1
            $table->string('party1_type')->nullable()->after('venue_city');
            $table->string('party1_name')->nullable()->after('party1_type');
            $table->string('party1_national_id')->nullable()->after('party1_name');
            $table->string('party1_phone')->nullable()->after('party1_national_id');
            $table->string('party1_email')->nullable()->after('party1_phone');
            $table->string('party1_address')->nullable()->after('party1_email');

            // Party 2
            $table->string('party2_type')->nullable()->after('party1_address');
            $table->string('party2_name')->nullable()->after('party2_type');
            $table->string('party2_national_id')->nullable()->after('party2_name');
            $table->string('party2_phone')->nullable()->after('party2_national_id');
            $table->string('party2_email')->nullable()->after('party2_phone');
            $table->string('party2_address')->nullable()->after('party2_email');

            // Preamble
            $table->text('preamble')->nullable()->after('party2_address');

            // Clauses stored as JSON array: [{title, content}]
            // contract_clauses already exists, we'll reuse it
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $cols = [
                'contract_name','contract_nature','contract_date','venue','venue_address','venue_city',
                'party1_type','party1_name','party1_national_id','party1_phone','party1_email','party1_address',
                'party2_type','party2_name','party2_national_id','party2_phone','party2_email','party2_address',
                'preamble',
            ];
            foreach ($cols as $c) {
                if (Schema::hasColumn('contracts', $c)) $table->dropColumn($c);
            }
        });
    }
};
