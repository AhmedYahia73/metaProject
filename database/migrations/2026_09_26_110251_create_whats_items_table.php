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
        Schema::create('whats_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone')->nullable();
            $table->string('phone_number_id')->nullable()->index();
            $table->string('waba_id')->nullable();
            $table->text('access_token')->nullable();
            $table->string('phone_status')->default('pending_otp');
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('android_link')->nullable();
            $table->string('ios_link')->nullable();
            $table->integer('msg_number')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whats_items');
    }
};
