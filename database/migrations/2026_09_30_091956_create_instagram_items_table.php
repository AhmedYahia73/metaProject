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
        Schema::create('instagram_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('instagram_id')->unique()->comment('Instagram Business / Professional Account ID');
            $table->string('username')->nullable()->comment('Instagram username');
            $table->string('name')->nullable()->comment('Instagram account display name');
            $table->text('profile_picture_url')->nullable()->comment('Instagram profile image URL');
            $table->string('page_id')->nullable()->comment('Connected Facebook Page ID');
            $table->text('access_token')->comment('Long-lived Instagram / Page Access Token');
            $table->string('verify_token')->unique()->comment('Auto-generated token for Meta webhook verification');
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->longText('ai_context')->nullable();
            $table->string('ai_file')->nullable();
            $table->string('android_link')->nullable();
            $table->string('ios_link')->nullable();
            $table->string('website_url')->nullable();
            $table->integer('msg_number')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instagram_items');
    }
};
