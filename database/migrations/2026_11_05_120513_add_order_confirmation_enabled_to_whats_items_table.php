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
        Schema::table('whats_items', function (Blueprint $table) {
            $table->boolean('order_confirmation_enabled')->default(false)->after('phone_status')->index();
            $table->string('ecommerce_platform')->nullable()->default('woocommerce')->after('order_confirmation_enabled');
            $table->string('store_webhook_token')->nullable()->unique()->after('ecommerce_platform');
            $table->text('shopify_access_token')->nullable()->after('store_webhook_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_items', function (Blueprint $table) {
            $table->dropColumn([
                'order_confirmation_enabled',
                'ecommerce_platform',
                'store_webhook_token',
                'shopify_access_token',
            ]);
        });
    }
};
