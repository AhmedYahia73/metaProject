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
        Schema::create('tiktok_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('open_id')->unique()->comment('TikTok Open ID / Business Account ID');
            $table->string('username')->nullable()->comment('TikTok username');
            $table->string('display_name')->nullable()->comment('TikTok display name');
            $table->string('avatar_url', 1000)->nullable()->comment('Avatar URL');
            $table->text('access_token')->comment('TikTok API Access Token');
            $table->text('refresh_token')->nullable()->comment('TikTok Refresh Token');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->string('verify_token')->nullable()->comment('Secret verify token for webhooks');
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->longText('ai_context')->nullable();
            $table->string('ai_file')->nullable();
            $table->string('android_link', 500)->nullable();
            $table->string('ios_link', 500)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->integer('msg_number')->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tiktok_accounts');
    }
};
