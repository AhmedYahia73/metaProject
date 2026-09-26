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
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('messenger_account_id')->nullable()->after('user_id')->constrained('messenger_accounts')->nullOnDelete();
            $table->foreignId('whats_item_id')->nullable()->after('messenger_account_id')->constrained('whats_items')->nullOnDelete();
            $table->boolean('is_read')->default(false)->after('is_admin')->index();
            $table->timestamp('read_at')->nullable()->after('is_read');
            $table->string('sender_type', 20)->default('customer')->after('read_at');
            $table->string('meta_message_id')->nullable()->after('sender_type')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('messenger_account_id');
            $table->dropConstrainedForeignId('whats_item_id');
            $table->dropColumn(['is_read', 'read_at', 'sender_type', 'meta_message_id']);
        });
    }
};
