<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cpu_id')->constrained('components')->cascadeOnDelete();
            $table->foreignId('gpu_id')->constrained('components')->cascadeOnDelete();
            $table->string('game');
            $table->unsignedSmallInteger('fps');
            $table->string('resolution')->default('1440P');
            $table->string('settings')->default('Ultra');
            $table->timestamps();

            $table->unique(['cpu_id', 'gpu_id', 'game', 'resolution', 'settings']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmarks');
    }
};
