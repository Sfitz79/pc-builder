<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('components')->restrictOnDelete();
            $table->string('category')->index();
            $table->decimal('price_snapshot', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['build_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_components');
    }
};
