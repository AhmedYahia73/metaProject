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
            DB::statement("ALTER TABLE orders MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram', 'telegram') NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE packages MODIFY COLUMN type ENUM('whats', 'face', 'all', 'instagram', 'telegram') NOT NULL DEFAULT 'all'");
            DB::statement("ALTER TABLE msg_sends MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram', 'telegram') NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE chats MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram', 'telegram') NOT NULL DEFAULT 'whatsapp'");
        }

        // 2. Add telegram_account_id to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('telegram_account_id')
                ->nullable()
                ->after('instagram_item_id')
                ->constrained('telegram_accounts')
                ->nullOnDelete();
        });

        // 3. Add telegram_account_id to msg_sends
        Schema::table('msg_sends', function (Blueprint $table) {
            $table->foreignId('telegram_account_id')
                ->nullable()
                ->after('instagram_item_id')
                ->constrained('telegram_accounts')
                ->nullOnDelete();
        });

        // 4. Add telegram_account_id and telegram_sender_id to chats
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('telegram_account_id')
                ->nullable()
                ->after('instagram_item_id')
                ->constrained('telegram_accounts')
                ->nullOnDelete();
            $table->string('telegram_sender_id')
                ->nullable()
                ->after('instagram_sender_id')
                ->index();
        });

        // 5. Add telegram_account_id to daily_tokens
        Schema::table('daily_tokens', function (Blueprint $table) {
            $table->foreignId('telegram_account_id')
                ->nullable()
                ->after('instagram_item_id')
                ->constrained('telegram_accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telegram_account_id');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telegram_account_id');
            $table->dropColumn('telegram_sender_id');
        });

        Schema::table('msg_sends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telegram_account_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telegram_account_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram') NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE packages MODIFY COLUMN type ENUM('whats', 'face', 'all', 'instagram') NOT NULL DEFAULT 'all'");
            DB::statement("ALTER TABLE msg_sends MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram') NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE chats MODIFY COLUMN channel ENUM('whatsapp', 'messenger', 'instagram') NOT NULL DEFAULT 'whatsapp'");
        }
    }
};
