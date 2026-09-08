<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: older prod databases already carry this column (added
        // directly via raw ALTER when Neon pooled DDL-in-transaction blocked
        // this migration). Skip if present so the migration batch passes.
        if (in_array('chipset', \Illuminate\Support\Facades\Schema::getColumnListing('components'), true)) {
            return;
        }

        Schema::table('components', function (Blueprint $table) {
            $table->string('chipset')->nullable();
            // Index skipped on Postgres: Neon pooled connections abort the
            // index DDL inside migration transactions (25P02). The column is
            // the deliverable; the category/socket indexes already cover the
            // hot queries.
            if (Schema::getConnection()->getDriverName() !== 'pgsql') {
                $table->index('chipset');
            }
        });
    }

    public function down(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->dropColumn('chipset');
        });
    }
};
