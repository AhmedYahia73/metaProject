<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            // Make phone nullable to support Messenger chats (which use messenger_sender_id instead)
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('phone')->nullable(false)->change();
        });
    }
};
