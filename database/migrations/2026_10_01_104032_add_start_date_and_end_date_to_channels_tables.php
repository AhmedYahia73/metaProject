<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Messenger Accounts
        if (Schema::hasTable('messenger_accounts')) {
            Schema::table('messenger_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('messenger_accounts', 'start_date')) {
                    $table->date('start_date')->nullable()->after('status');
                }
                if (! Schema::hasColumn('messenger_accounts', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
            });
        }

        // 2. Whats Items
        if (Schema::hasTable('whats_items')) {
            Schema::table('whats_items', function (Blueprint $table) {
                if (! Schema::hasColumn('whats_items', 'start_date')) {
                    $table->date('start_date')->nullable()->after('phone_status');
                }
                if (! Schema::hasColumn('whats_items', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
            });
        }

        // 3. Instagram Items
        if (Schema::hasTable('instagram_items')) {
            Schema::table('instagram_items', function (Blueprint $table) {
                if (! Schema::hasColumn('instagram_items', 'start_date')) {
                    $table->date('start_date')->nullable()->after('status');
                }
                if (! Schema::hasColumn('instagram_items', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
            });
        }

        // 4. Backfill from active approved orders if existing
        $this->backfillExistingChannels();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messenger_accounts')) {
            Schema::table('messenger_accounts', function (Blueprint $table) {
                $table->dropColumn(['start_date', 'end_date']);
            });
        }

        if (Schema::hasTable('whats_items')) {
            Schema::table('whats_items', function (Blueprint $table) {
                $table->dropColumn(['start_date', 'end_date']);
            });
        }

        if (Schema::hasTable('instagram_items')) {
            Schema::table('instagram_items', function (Blueprint $table) {
                $table->dropColumn(['start_date', 'end_date']);
            });
        }
    }

    /**
     * Backfill start_date and end_date for accounts that already have approved orders.
     */
    private function backfillExistingChannels(): void
    {
        // Messenger accounts
        $messengerOrders = DB::table('orders')
            ->where('status', 'approved')
            ->whereNotNull('messenger_account_id')
            ->orderBy('id', 'desc')
            ->get();

        foreach ($messengerOrders as $order) {
            DB::table('messenger_accounts')
                ->where('id', $order->messenger_account_id)
                ->whereNull('start_date')
                ->update([
                    'start_date' => $order->from,
                    'end_date' => $order->to,
                ]);
        }

        // WhatsApp items
        $whatsOrders = DB::table('orders')
            ->where('status', 'approved')
            ->whereNotNull('whats_item_id')
            ->orderBy('id', 'desc')
            ->get();

        foreach ($whatsOrders as $order) {
            DB::table('whats_items')
                ->where('id', $order->whats_item_id)
                ->whereNull('start_date')
                ->update([
                    'start_date' => $order->from,
                    'end_date' => $order->to,
                ]);
        }

        // Instagram items
        $instagramOrders = DB::table('orders')
            ->where('status', 'approved')
            ->whereNotNull('instagram_item_id')
            ->orderBy('id', 'desc')
            ->get();

        foreach ($instagramOrders as $order) {
            DB::table('instagram_items')
                ->where('id', $order->instagram_item_id)
                ->whereNull('start_date')
                ->update([
                    'start_date' => $order->from,
                    'end_date' => $order->to,
                ]);
        }
    }
};
