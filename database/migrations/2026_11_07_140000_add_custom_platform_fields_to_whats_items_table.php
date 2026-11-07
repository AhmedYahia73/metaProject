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
            $table->string('custom_api_key', 255)->nullable()->unique()->after('shopify_access_token');
            $table->string('api_confirm_url', 500)->nullable()->after('custom_api_key');
            $table->string('api_reject_url', 500)->nullable()->after('api_confirm_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_items', function (Blueprint $table) {
            $table->dropColumn([
                'custom_api_key',
                'api_confirm_url',
                'api_reject_url',
            ]);
        });
    }
};
