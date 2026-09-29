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
        Schema::table('msg_sends', function (Blueprint $table) {
            $table->foreignId('messenger_account_id')->nullable()->after('user_id')->constrained('messenger_accounts')->nullOnDelete();
            $table->foreignId('whats_item_id')->nullable()->after('messenger_account_id')->constrained('whats_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('msg_sends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('messenger_account_id');
            $table->dropConstrainedForeignId('whats_item_id');
        });
    }
};
