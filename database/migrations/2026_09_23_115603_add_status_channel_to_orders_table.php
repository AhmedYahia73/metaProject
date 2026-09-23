<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Status lifecycle: pending → approved | rejected
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('msgs');

            // Which channel this order activates
            $table->enum('channel', ['whatsapp', 'messenger'])->default('whatsapp')->after('status');

            // Link to the messenger page this order activates (null for whatsapp orders)
            $table->foreignId('messenger_account_id')
                ->nullable()
                ->after('channel')
                ->constrained('messenger_accounts')
                ->nullOnDelete();

            // Make from/to nullable — Messenger orders have them set on approval
            $table->date('from')->nullable()->change();
            $table->date('to')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['messenger_account_id']);
            $table->dropColumn(['status', 'channel', 'messenger_account_id']);
            $table->date('from')->nullable(false)->change();
            $table->date('to')->nullable(false)->change();
        });
    }
};
