<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_documents', function (Blueprint $table) {
            $table->string('mime_type')->nullable()->after('file_path');
            $table->string('file_extension', 20)->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('property_documents', function (Blueprint $table) {
            $table->dropColumn(['mime_type', 'file_extension']);
        });
    }
};
