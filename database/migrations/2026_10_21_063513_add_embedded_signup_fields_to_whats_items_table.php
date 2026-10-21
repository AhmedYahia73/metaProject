<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds fields needed to support WhatsApp Embedded Signup flow:
     * - token_type: 'system' (old approach) or 'customer' (Embedded Signup per-WABA token)
     * - customer_waba_id: the WABA ID created for the customer by Meta during Embedded Signup
     */
    public function up(): void
    {
        Schema::table('whats_items', function (Blueprint $table) {
            $table->string('token_type')->default('system')->after('access_token');
            $table->string('customer_waba_id')->nullable()->after('token_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_items', function (Blueprint $table) {
            $table->dropColumn(['token_type', 'customer_waba_id']);
        });
    }
};
