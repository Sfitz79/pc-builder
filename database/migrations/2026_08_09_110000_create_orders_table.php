<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('owner_token')->nullable()->index();
            $table->unsignedBigInteger('build_id')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('paypal_order_id')->nullable()->index();
            $table->string('paypal_capture_id')->nullable();
            $table->string('paypal_invoice_id')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->decimal('parts_total', 10, 2)->default(0);
            $table->decimal('build_delivery', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('paypal_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('build_id')->references('id')->on('builds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
