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
            $table->dropColumn([
                'access_token',
                'phone_number_id',
                'waba_id',
                'phone_status',
                'phone_verified_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('access_token', 500)->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('waba_id')->nullable();
            $table->string('phone_status')->default('pending_otp');
            $table->timestamp('phone_verified_at')->nullable();
        });
    }
};
