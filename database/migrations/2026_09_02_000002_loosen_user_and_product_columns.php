<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::table('software_products', function (Blueprint $table) {
            $table->unsignedInteger('warranty_days')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('software_products', function (Blueprint $table) {
            $table->integer('warranty_days')->nullable()->change();
        });
    }
};
