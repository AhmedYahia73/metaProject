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
                if (! Schema::hasColumn('messenger_accounts', 'api_link')) {
                    $table->string('api_link', 500)->nullable()->after('website_url');
                }
            });
        }

        // 2. Whats Items
        if (Schema::hasTable('whats_items')) {
            Schema::table('whats_items', function (Blueprint $table) {
                if (! Schema::hasColumn('whats_items', 'api_link')) {
                    $table->string('api_link', 500)->nullable()->after('website_url');
                }
            });
        }

        // 3. Instagram Items
        if (Schema::hasTable('instagram_items')) {
            Schema::table('instagram_items', function (Blueprint $table) {
                if (! Schema::hasColumn('instagram_items', 'api_link')) {
                    $table->string('api_link', 500)->nullable()->after('website_url');
                }
            });
        }

        // 4. Telegram Accounts
        if (Schema::hasTable('telegram_accounts')) {
            Schema::table('telegram_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('telegram_accounts', 'api_link')) {
                    $table->string('api_link', 500)->nullable()->after('website_url');
                }
            });
        }

        // 5. TikTok Accounts
        if (Schema::hasTable('tiktok_accounts')) {
            Schema::table('tiktok_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('tiktok_accounts', 'api_link')) {
                    $table->string('api_link', 500)->nullable()->after('website_url');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messenger_accounts')) {
            Schema::table('messenger_accounts', function (Blueprint $table) {
                if (Schema::hasColumn('messenger_accounts', 'api_link')) {
                    $table->dropColumn('api_link');
                }
            });
        }

        if (Schema::hasTable('whats_items')) {
            Schema::table('whats_items', function (Blueprint $table) {
                if (Schema::hasColumn('whats_items', 'api_link')) {
                    $table->dropColumn('api_link');
                }
            });
        }

        if (Schema::hasTable('instagram_items')) {
            Schema::table('instagram_items', function (Blueprint $table) {
                if (Schema::hasColumn('instagram_items', 'api_link')) {
                    $table->dropColumn('api_link');
                }
            });
        }

        if (Schema::hasTable('telegram_accounts')) {
            Schema::table('telegram_accounts', function (Blueprint $table) {
                if (Schema::hasColumn('telegram_accounts', 'api_link')) {
                    $table->dropColumn('api_link');
                }
            });
        }

        if (Schema::hasTable('tiktok_accounts')) {
            Schema::table('tiktok_accounts', function (Blueprint $table) {
                if (Schema::hasColumn('tiktok_accounts', 'api_link')) {
                    $table->dropColumn('api_link');
                }
            });
        }
    }
};
