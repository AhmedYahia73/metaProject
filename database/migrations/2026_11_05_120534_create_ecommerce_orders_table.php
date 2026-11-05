<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_item_id')->constrained('whats_items')->cascadeOnDelete();
            $table->string('platform'); // 'woocommerce', 'shopify'
            $table->string('external_order_id');
            $table->string('order_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone');
            $table->decimal('total_price', 12, 2)->nullable();
            $table->string('currency')->default('EGP');
            $table->string('status')->default('pending'); // 'pending', 'confirmed', 'cancelled', 'failed'
            $table->string('meta_message_id')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['whats_item_id', 'external_order_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_orders');
    }
};
