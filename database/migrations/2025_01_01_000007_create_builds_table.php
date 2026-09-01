<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('My Build');
            $table->string('purpose')->nullable();
            $table->string('resolution')->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->unsignedSmallInteger('performance_score')->default(0);
            $table->json('compatibility_checks')->nullable();
            $table->boolean('public')->default(false);
            $table->string('share_slug')->unique()->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builds');
    }
};
