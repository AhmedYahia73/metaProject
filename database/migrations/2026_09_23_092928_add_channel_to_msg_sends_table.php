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
            $table->enum('channel', ['whatsapp', 'messenger'])->default('whatsapp')->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('msg_sends', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
