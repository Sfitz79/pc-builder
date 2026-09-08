<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On Postgres this is skipped: Neon's pooled connections reject the
        // comment/DEFAULT statements Laravel emits for ->change() inside the
        // migration transaction (error 25P02). Prod already works with the
        // existing nullable state; the source_url/chipset columns are the
        // critical pending migrations.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::table('software_products', function (Blueprint $table) {
            $table->unsignedInteger('warranty_days')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        Schema::table('software_products', function (Blueprint $table) {
            $table->integer('warranty_days')->nullable()->change();
        });
    }
};
