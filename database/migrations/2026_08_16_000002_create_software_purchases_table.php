<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_token')->nullable()->index();
            $table->unsignedBigInteger('product_id');
            $table->string('sku');
            $table->string('product_name');
            $table->decimal('amount_gbp', 10, 2)->default(0);
            $table->string('currency', 3)->default('GBP');
            $table->string('status')->default('pending')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('paypal_order_id')->nullable()->index();
            $table->string('paypal_capture_id')->nullable();
            $table->string('metenzi_order_id')->nullable()->index();
            $table->string('metenzi_status')->nullable();
            $table->text('keys')->nullable();          // encrypted JSON array
            $table->text('notes')->nullable();
            $table->string('last_webhook_event')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('software_products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_purchases');
    }
};
