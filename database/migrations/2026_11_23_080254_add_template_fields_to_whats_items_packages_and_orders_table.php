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
            $table->integer('t_msg_number')->default(0)->after('msg_number');
            $table->date('t_start_date')->nullable()->after('end_date');
            $table->date('t_end_date')->nullable()->after('t_start_date');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('type');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whats_items', function (Blueprint $table) {
            $table->dropColumn(['t_msg_number', 't_start_date', 't_end_date']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['is_template']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_template']);
        });
    }
};
