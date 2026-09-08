<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: older prod databases already carry these columns (added
        // directly via raw ALTER when Neon pooled DDL-in-transaction blocked
        // this migration). Skip if present so the migration batch passes.
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('components');
        if (in_array('source_url', $cols, true) && in_array('image_url', $cols, true)) {
            return;
        }

        Schema::table('components', function (Blueprint $table) {
            $table->string('source_url')->nullable()->after('specs');
            $table->string('image_url')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'image_url']);
        });
    }
};
