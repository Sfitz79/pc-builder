<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_products', function (Blueprint $table) {
            $table->id();
            $table->string('metenzi_product_id')->unique();
            $table->string('sku');
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('platform')->nullable();
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->decimal('retail_price', 10, 2)->default(0);       // EUR from Metenzi
            $table->unsignedInteger('retail_price_cents')->default(0);
            $table->decimal('gbp_price', 10, 2)->default(0);          // converted for the store
            $table->string('currency', 3)->default('GBP');
            $table->integer('stock')->default(0);
            $table->boolean('active')->default(true);
            $table->integer('warranty_days')->nullable();
            $table->string('image_url')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->index('category');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_products');
    }
};
