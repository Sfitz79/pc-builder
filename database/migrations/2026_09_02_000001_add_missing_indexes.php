<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These are optional performance indexes. On Postgres they are skipped:
        // Neon's pooled connections abort inside multi-statement migration
        // transactions (error 25P02) when DDL is issued, which poisoned the
        // whole pending batch and blocked the source_url/chipset columns that
        // the catalogue seeder depends on. 1017 rows do not need these indexes;
        // the columns are the critical deliverable.
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            if (! Schema::hasIndex('builds', 'user_id')) {
                Schema::table('builds', function (Blueprint $table) {
                    $table->index('user_id');
                });
            }

            if (! Schema::hasIndex('orders', 'build_id')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->index('build_id');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        Schema::table('builds', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['build_id']);
        });
    }
};
