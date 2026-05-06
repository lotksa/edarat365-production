<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
            $table->decimal('ownership_ratio', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['unit_id', 'owner_id']);
        });

        // Migrate existing owner_id + ownership_ratio into the pivot
        $units = DB::table('units')
            ->whereNotNull('owner_id')
            ->select('id', 'owner_id', 'ownership_ratio')
            ->get();

        foreach ($units as $unit) {
            DB::table('unit_owners')->insert([
                'unit_id'         => $unit->id,
                'owner_id'        => $unit->owner_id,
                'ownership_ratio' => $unit->ownership_ratio ?? 100,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropColumn(['owner_id', 'ownership_ratio']);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('ownership_ratio', 5, 2)->default(100)->after('area');
            $table->foreignId('owner_id')->nullable()->after('ownership_ratio')->constrained('owners')->nullOnDelete();
        });

        $pivots = DB::table('unit_owners')->get();
        foreach ($pivots as $p) {
            DB::table('units')->where('id', $p->unit_id)->update([
                'owner_id'        => $p->owner_id,
                'ownership_ratio' => $p->ownership_ratio,
            ]);
        }

        Schema::dropIfExists('unit_owners');
    }
};
