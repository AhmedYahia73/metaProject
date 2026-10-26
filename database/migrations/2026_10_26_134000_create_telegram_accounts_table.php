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
        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('bot_token')->comment('Telegram Bot API Token');
            $table->string('bot_id')->nullable()->unique()->comment('Telegram Bot ID');
            $table->string('bot_username')->nullable()->comment('Telegram Bot username without @');
            $table->string('bot_name')->nullable()->comment('Telegram Bot display name');
            $table->string('verify_token')->nullable()->comment('Secret token for Telegram webhook validation');
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
        Schema::dropIfExists('telegram_accounts');
    }
};
