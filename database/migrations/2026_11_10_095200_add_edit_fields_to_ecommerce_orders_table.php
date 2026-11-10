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
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->string('edit_state')->nullable()->after('status'); // null, 'awaiting_edit', 'editing', 'edited'
            $table->json('edit_items_snapshot')->nullable()->after('raw_payload');
            $table->timestamp('edited_at')->nullable()->after('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->dropColumn(['edit_state', 'edit_items_snapshot', 'edited_at']);
        });
    }
};
