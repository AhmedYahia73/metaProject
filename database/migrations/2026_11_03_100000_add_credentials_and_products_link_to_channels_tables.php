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
        $tables = [
            'messenger_accounts',
            'whats_items',
            'instagram_items',
            'telegram_accounts',
            'tiktok_accounts',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (! Schema::hasColumn($tableName, 'login_link')) {
                        $table->string('login_link', 500)->nullable();
                    }
                    if (! Schema::hasColumn($tableName, 'user_name')) {
                        $table->string('user_name', 255)->nullable();
                    }
                    if (! Schema::hasColumn($tableName, 'password')) {
                        $table->string('password', 255)->nullable();
                    }
                    if (! Schema::hasColumn($tableName, 'products_link')) {
                        $table->string('products_link', 500)->nullable();
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'messenger_accounts',
            'whats_items',
            'instagram_items',
            'telegram_accounts',
            'tiktok_accounts',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $columnsToDrop = [];
                    foreach (['login_link', 'user_name', 'password', 'products_link'] as $col) {
                        if (Schema::hasColumn($tableName, $col)) {
                            $columnsToDrop[] = $col;
                        }
                    }
                    if (! empty($columnsToDrop)) {
                        $table->dropColumn($columnsToDrop);
                    }
                });
            }
        }
    }
};
