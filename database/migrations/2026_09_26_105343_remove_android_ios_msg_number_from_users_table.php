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
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['android_link', 'ios_link', 'msg_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('android_link')->nullable()->after('restuarant_name');
            $table->string('ios_link')->nullable()->after('android_link');
            $table->integer('msg_number')->default(0)->after('ios_link');
        });
    }
};
