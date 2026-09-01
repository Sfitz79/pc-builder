<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('components')->cascadeOnDelete();
            $table->string('attribute');
            $table->string('value');
            $table->timestamps();

            $table->unique(['component_id', 'attribute']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_attributes');
    }
};
