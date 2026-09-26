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
        Schema::table('whats_items', function (Blueprint $table) {
            $table->longText('ai_context')->nullable()->after('msg_number');
            $table->string('ai_file')->nullable()->after('ai_context');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_items', function (Blueprint $table) {
            $table->dropColumn(['ai_context', 'ai_file']);
        });
    }
};
