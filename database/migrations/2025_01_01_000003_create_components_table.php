<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('manufacturer_id')->constrained('manufacturers');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable()->index();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('GBP');
            $table->string('socket')->nullable()->index();
            $table->unsignedSmallInteger('wattage')->nullable()->index();
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->json('specs')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('components');
    }
};
