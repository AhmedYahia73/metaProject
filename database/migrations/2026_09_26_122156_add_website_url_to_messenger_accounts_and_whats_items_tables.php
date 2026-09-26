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
        Schema::table('messenger_accounts', function (Blueprint $table) {
            $table->string('website_url', 500)->nullable()->after('ios_link');
        });

        Schema::table('whats_items', function (Blueprint $table) {
            $table->string('website_url', 500)->nullable()->after('ios_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messenger_accounts', function (Blueprint $table) {
            $table->dropColumn('website_url');
        });

        Schema::table('whats_items', function (Blueprint $table) {
            $table->dropColumn('website_url');
        });
    }
};
