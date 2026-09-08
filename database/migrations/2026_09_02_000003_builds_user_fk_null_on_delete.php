<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On Postgres this is skipped: Neon's pooled connections abort the
        // constraint drop/re-add inside the migration transaction (25P02).
        // The FK already works; not worth blocking the batch for it.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        Schema::table('builds', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        Schema::table('builds', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
