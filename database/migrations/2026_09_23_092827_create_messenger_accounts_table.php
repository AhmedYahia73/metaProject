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
        Schema::create('messenger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page_id')->unique()->comment('Facebook Page ID');
            $table->string('page_name')->nullable()->comment('Facebook Page display name');
            $table->text('page_access_token')->comment('Long-lived Page Access Token');
            $table->string('verify_token')->unique()->comment('Auto-generated token for Meta webhook verification');
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messenger_accounts');
    }
};
