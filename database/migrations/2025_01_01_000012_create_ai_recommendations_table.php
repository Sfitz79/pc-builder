<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->string('resolution')->nullable();
            $table->json('recommendation');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
    }
};
