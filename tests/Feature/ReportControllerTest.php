<?php

use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\WhatsItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('unauthenticated user cannot access reports', function () {
    $this->getJson('/api/admin/reports/orders')->assertStatus(401);
    $this->getJson('/api/admin/reports/messages')->assertStatus(401);
    $this->getJson('/api/admin/reports/subscribers')->assertStatus(401);
});

test('admin can get order report with totals per channel and package', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);

    $packageA = Package::create([
        'name' => ['ar' => 'باقة أ', 'en' => 'Package A'],
        'msg_number' => 500,
        'price' => 100.00,
        'months' => 1,
    ]);

    $packageB = Package::create([
        'name' => ['ar' => 'باقة ب', 'en' => 'Package B'],
        'msg_number' => 1000,
        'price' => 200.00,
        'months' => 1,
    ]);

    // Order 1: WhatsApp - Package A - 100 - Approved
    $order1 = Order::create([
        'user_id' => $user->id,
        'package_id' => $packageA->id,
        'channel' => 'whatsapp',
        'price' => 100.00,
        'final_price' => 100.00,
        'status' => 'approved',
    ]);
    $order1->created_at = Carbon::parse('2026-10-01 10:00:00');
    $order1->saveQuietly();

    // Order 2: Messenger - Package B - 200 - Approved
    $order2 = Order::create([
        'user_id' => $user->id,
        'package_id' => $packageB->id,
        'channel' => 'messenger',
        'price' => 200.00,
        'final_price' => 200.00,
        'status' => 'approved',
    ]);
    $order2->created_at = Carbon::parse('2026-10-02 10:00:00');
    $order2->saveQuietly();

    // Order 3: Instagram - Package A - 100 - Pending (should not count by default if status=approved)
    $order3 = Order::create([
        'user_id' => $user->id,
        'package_id' => $packageA->id,
        'channel' => 'instagram',
        'price' => 100.00,
        'final_price' => 100.00,
        'status' => 'pending',
    ]);
    $order3->created_at = Carbon::parse('2026-10-03 10:00:00');
    $order3->saveQuietly();

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/reports/orders?from=2026-10-01&to=2026-10-05')
        ->assertStatus(200)
        ->assertJson([
            'status' => true,
            'data' => [
                'total_final_price' => 300,
                'total_orders' => 2,
                'channels' => [
                    'whatsapp' => 100,
                    'messenger' => 200,
                    'instagram' => 0,
                ],
            ],
        ]);

    $data = $response->json('data');
    expect($data['packages_summary'])->toHaveKey('Package A', 100);
    expect($data['packages_summary'])->toHaveKey('Package B', 200);
});

test('admin can get message consumption report per channel with date filter', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);

    // Create message sends on WhatsApp, Messenger, Instagram
    $msg1 = MsgSend::create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
    ]);
    $msg1->created_at = Carbon::parse('2026-10-02 12:00:00');
    $msg1->saveQuietly();

    $msg2 = MsgSend::create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
    ]);
    $msg2->created_at = Carbon::parse('2026-10-02 13:00:00');
    $msg2->saveQuietly();

    $msg3 = MsgSend::create([
        'user_id' => $user->id,
        'channel' => 'messenger',
    ]);
    $msg3->created_at = Carbon::parse('2026-10-02 14:00:00');
    $msg3->saveQuietly();

    // Outside date filter
    $msg4 = MsgSend::create([
        'user_id' => $user->id,
        'channel' => 'instagram',
    ]);
    $msg4->created_at = Carbon::parse('2026-09-01 10:00:00');
    $msg4->saveQuietly();

    $this->actingAs($admin)
        ->getJson('/api/admin/reports/messages?from=2026-10-01&to=2026-10-05')
        ->assertStatus(200)
        ->assertJson([
            'status' => true,
            'data' => [
                'total_messages' => 3,
                'channels' => [
                    'whatsapp' => 2,
                    'messenger' => 1,
                    'instagram' => 0,
                ],
            ],
        ]);
});

test('admin can get subscribers report ordered by highest total spending', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $userHigh = User::factory()->create(['role' => 'user', 'name' => 'High Spender']);
    $userLow = User::factory()->create(['role' => 'user', 'name' => 'Low Spender']);

    $package = Package::create([
        'name' => ['ar' => 'باقة', 'en' => 'Package'],
        'msg_number' => 100,
        'price' => 50.00,
        'months' => 1,
    ]);

    // userHigh spent 500
    Order::create([
        'user_id' => $userHigh->id,
        'package_id' => $package->id,
        'channel' => 'whatsapp',
        'price' => 500.00,
        'final_price' => 500.00,
        'status' => 'approved',
    ]);

    // userLow spent 150
    Order::create([
        'user_id' => $userLow->id,
        'package_id' => $package->id,
        'channel' => 'messenger',
        'price' => 150.00,
        'final_price' => 150.00,
        'status' => 'approved',
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/reports/subscribers')
        ->assertStatus(200)
        ->assertJson(['status' => true]);

    $items = $response->json('data.data');
    expect($items)->toBeArray();
    expect($items[0]['id'])->toBe($userHigh->id);
    expect($items[0]['total_paid'])->toEqual(500);
    expect($items[1]['id'])->toBe($userLow->id);
    expect($items[1]['total_paid'])->toEqual(150);
});

test('admin can get expiring subscriptions report', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);

    // WhatsItem expiring in 3 days
    WhatsItem::create([
        'user_id' => $user->id,
        'phone' => '201011111111',
        'phone_status' => 'active',
        'msg_number' => 100,
        'start_date' => Carbon::now()->subDays(25)->toDateString(),
        'end_date' => Carbon::now()->addDays(3)->toDateString(),
    ]);

    // MessengerAccount expiring in 20 days (outside 7 days window)
    MessengerAccount::create([
        'user_id' => $user->id,
        'page_id' => 'page_123',
        'page_name' => 'Test Page',
        'page_access_token' => 'token',
        'verify_token' => 'verify_123',
        'msg_number' => 100,
        'start_date' => Carbon::now()->subDays(10)->toDateString(),
        'end_date' => Carbon::now()->addDays(20)->toDateString(),
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/reports/expiring-subscriptions?days=7')
        ->assertStatus(200)
        ->assertJson([
            'status' => true,
            'data' => [
                'expiring_count' => 1,
            ],
        ]);

    $data = $response->json('data.items');
    expect($data[0]['channel'])->toBe('whatsapp');
});
