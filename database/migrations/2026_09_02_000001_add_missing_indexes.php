<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('build_id');
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['build_id']);
        });
    }
};
