<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $owners = DB::table('owners')->orderBy('id')->get(['id']);
        $num = 100000;
        foreach ($owners as $owner) {
            DB::table('owners')->where('id', $owner->id)->update(['account_number' => (string) $num]);
            $num++;
        }
    }

    public function down(): void
    {
        // irreversible
    }
};
