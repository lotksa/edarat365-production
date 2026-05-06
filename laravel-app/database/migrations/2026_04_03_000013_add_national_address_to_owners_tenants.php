<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->boolean('has_national_address')->default(false)->after('email');
            $table->string('address_type')->nullable()->after('has_national_address'); // full, short
            $table->string('address_short_code')->nullable()->after('address_type');
            $table->string('address_region')->nullable()->after('address_short_code');
            $table->string('address_city')->nullable()->after('address_region');
            $table->string('address_district')->nullable()->after('address_city');
            $table->string('address_street')->nullable()->after('address_district');
            $table->string('address_building_no')->nullable()->after('address_street');
            $table->string('address_additional_no')->nullable()->after('address_building_no');
            $table->string('address_postal_code')->nullable()->after('address_additional_no');
            $table->string('address_unit_no')->nullable()->after('address_postal_code');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('has_national_address')->default(false)->after('email');
            $table->string('address_type')->nullable()->after('has_national_address');
            $table->string('address_short_code')->nullable()->after('address_type');
            $table->string('address_region')->nullable()->after('address_short_code');
            $table->string('address_city')->nullable()->after('address_region');
            $table->string('address_district')->nullable()->after('address_city');
            $table->string('address_street')->nullable()->after('address_district');
            $table->string('address_building_no')->nullable()->after('address_street');
            $table->string('address_additional_no')->nullable()->after('address_building_no');
            $table->string('address_postal_code')->nullable()->after('address_additional_no');
            $table->string('address_unit_no')->nullable()->after('address_postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn([
                'has_national_address', 'address_type', 'address_short_code',
                'address_region', 'address_city', 'address_district', 'address_street',
                'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no',
            ]);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'has_national_address', 'address_type', 'address_short_code',
                'address_region', 'address_city', 'address_district', 'address_street',
                'address_building_no', 'address_additional_no', 'address_postal_code', 'address_unit_no',
            ]);
        });
    }
};
