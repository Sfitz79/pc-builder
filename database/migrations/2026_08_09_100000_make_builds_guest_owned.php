<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('owner_token', 64)->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->dropIndex(['owner_token']);
            $table->dropColumn('owner_token');
        });
    }
};
