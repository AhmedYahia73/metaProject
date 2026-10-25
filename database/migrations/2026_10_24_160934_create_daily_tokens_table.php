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
        Schema::create('daily_tokens', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('tokens')->default(0);
            $table->foreignId('whats_item_id')->nullable()->constrained('whats_items')->nullOnDelete();
            $table->foreignId('messenger_account_id')->nullable()->constrained('messenger_accounts')->nullOnDelete();
            $table->foreignId('instagram_item_id')->nullable()->constrained('instagram_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['date', 'whats_item_id']);
            $table->index(['date', 'messenger_account_id']);
            $table->index(['date', 'instagram_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_tokens');
    }
};
