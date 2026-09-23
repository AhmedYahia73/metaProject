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
            $table->enum('channel', ['whatsapp', 'messenger'])->default('whatsapp')->after('is_admin');
            // PSID (Page-Scoped ID) of the Messenger sender — null for WhatsApp chats
            $table->string('messenger_sender_id')->nullable()->after('channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['channel', 'messenger_sender_id']);
        });
    }
};
