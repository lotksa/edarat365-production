<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->string('account_number')->unique()->nullable()->after('user_id');
            $table->string('full_name_en')->nullable()->after('full_name');
            $table->string('association_name')->nullable()->after('email');
            $table->decimal('ownership_percentage', 5, 2)->default(0)->after('association_name');
            $table->string('mullak_status')->default('unregistered')->after('ownership_percentage');
            $table->text('address')->nullable()->after('mullak_status');
            $table->string('city')->nullable()->after('address');
            $table->text('notes')->nullable()->after('city');
            $table->string('status')->default('active')->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn([
                'account_number',
                'full_name_en',
                'association_name',
                'ownership_percentage',
                'mullak_status',
                'address',
                'city',
                'notes',
                'status',
            ]);
        });
    }
};
