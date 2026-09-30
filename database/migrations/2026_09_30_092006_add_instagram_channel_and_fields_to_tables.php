<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Modify enum definitions on MySQL/MariaDB engines
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram') NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE packages MODIFY COLUMN type ENUM('whats', 'face', 'all', 'instagram') NOT NULL DEFAULT 'all'");
            DB::statement("ALTER TABLE msg_sends MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram') NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE chats MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram') NOT NULL DEFAULT 'whatsapp'");
        }

        // 2. Add instagram_item_id to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('instagram_item_id')
                ->nullable()
                ->after('whats_item_id')
                ->constrained('instagram_items')
                ->nullOnDelete();
        });

        // 3. Add instagram_item_id to msg_sends
        Schema::table('msg_sends', function (Blueprint $table) {
            $table->foreignId('instagram_item_id')
                ->nullable()
                ->after('whats_item_id')
                ->constrained('instagram_items')
                ->nullOnDelete();
        });

        // 4. Add instagram_item_id and instagram_sender_id to chats
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('instagram_item_id')
                ->nullable()
                ->after('whats_item_id')
                ->constrained('instagram_items')
                ->nullOnDelete();
            $table->string('instagram_sender_id')
                ->nullable()
                ->after('messenger_sender_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instagram_item_id');
            $table->dropColumn('instagram_sender_id');
        });

        Schema::table('msg_sends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instagram_item_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instagram_item_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE chats MODIFY COLUMN channel ENUM('whatsapp', 'messenger') NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE msg_sends MODIFY COLUMN channel ENUM('whatsapp', 'messenger') NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE packages MODIFY COLUMN type ENUM('whats', 'face', 'all') NOT NULL DEFAULT 'all'");
            DB::statement("ALTER TABLE orders MODIFY COLUMN channel ENUM('whatsapp', 'messenger') NOT NULL DEFAULT 'whatsapp'");
        }
    }
};
