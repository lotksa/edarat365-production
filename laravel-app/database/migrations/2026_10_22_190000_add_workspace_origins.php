<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing relationships remain authoritative. NULL legacy rows are NOT
        // reassigned: an owner/president can legitimately serve several associations.
        foreach (['owners', 'tenants', 'association_managers', 'property_managers',
            'legal_representatives', 'vouchers', 'approval_requests',
            'activity_logs', 'notifications'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'association_id')) {
                Schema::table($name, function (Blueprint $table) use ($name) {
                    $foreign = $table->foreignId('association_id')->nullable()->index()->constrained('associations');
                    if (in_array($name, ['activity_logs', 'notifications'], true)) {
                        $foreign->nullOnDelete();
                    } else {
                        $foreign->restrictOnDelete();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // Deliberately retained: rollback must not discard workspace assignments.
    }
};
