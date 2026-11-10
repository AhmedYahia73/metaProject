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
            $table->string('api_edit_url')->nullable()->after('api_reject_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_items', function (Blueprint $table) {
            $table->dropColumn('api_edit_url');
        });
    }
};
