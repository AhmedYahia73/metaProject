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
        // 1. Messenger Accounts
        if (Schema::hasTable('messenger_accounts')) {
            Schema::table('messenger_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('messenger_accounts', 'user_data')) {
                    $table->json('user_data')->nullable()->after('api_link');
                }
            });
        }

        // 2. Whats Items
        if (Schema::hasTable('whats_items')) {
            Schema::table('whats_items', function (Blueprint $table) {
                if (! Schema::hasColumn('whats_items', 'user_data')) {
                    $table->json('user_data')->nullable()->after('api_link');
                }
            });
        }

        // 3. Instagram Items
        if (Schema::hasTable('instagram_items')) {
            Schema::table('instagram_items', function (Blueprint $table) {
                if (! Schema::hasColumn('instagram_items', 'user_data')) {
                    $table->json('user_data')->nullable()->after('api_link');
                }
            });
        }

        // 4. Telegram Accounts
        if (Schema::hasTable('telegram_accounts')) {
            Schema::table('telegram_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('telegram_accounts', 'user_data')) {
                    $table->json('user_data')->nullable()->after('api_link');
                }
            });
        }

        // 5. TikTok Accounts
        if (Schema::hasTable('tiktok_accounts')) {
            Schema::table('tiktok_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('tiktok_accounts', 'user_data')) {
                    $table->json('user_data')->nullable()->after('api_link');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messenger_accounts') && Schema::hasColumn('messenger_accounts', 'user_data')) {
            Schema::table('messenger_accounts', function (Blueprint $table) {
                $table->dropColumn('user_data');
            });
        }

        if (Schema::hasTable('whats_items') && Schema::hasColumn('whats_items', 'user_data')) {
            Schema::table('whats_items', function (Blueprint $table) {
                $table->dropColumn('user_data');
            });
        }

        if (Schema::hasTable('instagram_items') && Schema::hasColumn('instagram_items', 'user_data')) {
            Schema::table('instagram_items', function (Blueprint $table) {
                $table->dropColumn('user_data');
            });
        }

        if (Schema::hasTable('telegram_accounts') && Schema::hasColumn('telegram_accounts', 'user_data')) {
            Schema::table('telegram_accounts', function (Blueprint $table) {
                $table->dropColumn('user_data');
            });
        }

        if (Schema::hasTable('tiktok_accounts') && Schema::hasColumn('tiktok_accounts', 'user_data')) {
            Schema::table('tiktok_accounts', function (Blueprint $table) {
                $table->dropColumn('user_data');
            });
        }
    }
};
