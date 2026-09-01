<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('components')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('GBP');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['component_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_history');
    }
};
