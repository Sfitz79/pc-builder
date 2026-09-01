<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('components')->cascadeOnDelete();
            $table->string('image');
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_images');
    }
};
