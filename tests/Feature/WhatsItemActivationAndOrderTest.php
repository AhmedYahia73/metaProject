<?php

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.meta.waba_id' => '1234567890',
        'services.meta.phone_number_id' => '9876543210',
        'services.meta.system_user_token' => 'meta_test_token_123',
        'services.meta.graph_version' => 'v21.0',
        'services.meta.verify_token' => 'test',
    ]);
});

test('user can add a new whatsapp number and auto request otp code', function () {
    Http::fake([
        'https://graph.facebook.com/*/1234567890/phone_numbers*' => Http::response([
            'id' => 'meta_phone_id_999',
        ], 200),
        'https://graph.facebook.com/*/meta_phone_id_999/request_code*' => Http::response([
            'success' => true,
        ], 200),
    ]);

    $user = User::factory()->create([
        'role' => 'user',
        'restuarant_name' => 'Burger House',
    ]);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/user/whats/items', [
        'phone' => '01012345678',
        'android_link' => 'https://play.google.com/store/apps/details?id=com.burger',
        'ios_link' => 'https://apps.apple.com/app/burger',
        'auto_request_code' => true,
        'code_method' => 'SMS',
    ]);

    $response->assertCreated();
    $response->assertJson([
        'status' => true,
        'data' => [
            'phone' => '01012345678',
            'phone_number_id' => 'meta_phone_id_999',
            'phone_status' => 'pending_otp',
            'msg_number' => 0,
        ],
    ]);

    $this->assertDatabaseHas('whats_items', [
        'user_id' => $user->id,
        'phone' => '01012345678',
        'phone_number_id' => 'meta_phone_id_999',
        'phone_status' => 'pending_otp',
    ]);
});

test('user can list their own whatsapp items via items and pages endpoints', function () {
    $user1 = User::factory()->create(['role' => 'user']);
    $user2 = User::factory()->create(['role' => 'user']);

    WhatsItem::factory()->count(2)->create(['user_id' => $user1->id]);
    WhatsItem::factory()->create(['user_id' => $user2->id]);

    Sanctum::actingAs($user1);

    $response1 = $this->getJson('/api/user/whats/items');
    $response1->assertOk();
    $response1->assertJsonCount(2, 'data');

    $response2 = $this->getJson('/api/user/whats/pages');
    $response2->assertOk();
    $response2->assertJsonCount(2, 'data');
});

test('user can request otp verification code for their whats item', function () {
    Http::fake([
        'https://graph.facebook.com/*/meta_pid_123/request_code*' => Http::response([
            'success' => true,
        ], 200),
    ]);

    $user = User::factory()->create(['role' => 'user']);
    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01012345678',
        'phone_number_id' => 'meta_pid_123',
        'phone_status' => 'pending_otp',
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/user/whats/items/{$whatsItem->id}/request-code", [
        'code_method' => 'SMS',
    ]);

    $response->assertOk();
    $response->assertJson([
        'status' => true,
        'message' => 'Verification code sent to 01012345678 via SMS.',
    ]);
});

test('user can verify otp and register with 6-digit pin', function () {
    Http::fake([
        'https://graph.facebook.com/*/meta_pid_123/verify_code*' => Http::response([
            'success' => true,
        ], 200),
        'https://graph.facebook.com/*/meta_pid_123/register*' => Http::response([
            'success' => true,
        ], 200),
    ]);

    $user = User::factory()->create(['role' => 'user']);
    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01012345678',
        'phone_number_id' => 'meta_pid_123',
        'phone_status' => 'pending_otp',
        'phone_verified_at' => null,
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/user/whats/items/{$whatsItem->id}/verify-and-register", [
        'code' => '123456',
        'pin' => '654321',
    ]);

    $response->assertOk();
    $response->assertJson([
        'status' => true,
        'data' => [
            'id' => $whatsItem->id,
            'phone_status' => 'active',
        ],
    ]);

    $fresh = $whatsItem->fresh();
    expect($fresh->phone_status)->toBe('active');
    expect($fresh->phone_verified_at)->not->toBeNull();
});

test('user can sync meta status directly from meta graph api', function () {
    Http::fake([
        'https://graph.facebook.com/*/meta_pid_123*' => Http::response([
            'id' => 'meta_pid_123',
            'status' => 'CONNECTED',
            'code_verification_status' => 'VERIFIED',
        ], 200),
    ]);

    $user = User::factory()->create(['role' => 'user']);
    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone_number_id' => 'meta_pid_123',
        'phone_status' => 'pending_otp',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/user/whats/items/{$whatsItem->id}/meta-status");

    $response->assertOk();
    $response->assertJson([
        'status' => true,
        'data' => [
            'phone_status' => 'active',
        ],
    ]);

    expect($whatsItem->fresh()->phone_status)->toBe('active');
});

test('user can request whatsapp subscription order for their whats item', function () {
    $user = User::factory()->create(['role' => 'user']);
    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01012345678',
        'msg_number' => 0,
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة واتساب', 'en' => 'WhatsApp Package'],
        'msg_number' => 2000,
        'price' => 150,
        'months' => 1,
        'type' => 'whats',
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/user/whats/orders', [
        'whats_item_id' => $whatsItem->id,
        'package_id' => $package->id,
    ]);

    $response->assertCreated();
    $response->assertJson([
        'status' => true,
        'data' => [
            'whats_item_id' => $whatsItem->id,
            'status' => 'pending',
            'price' => 150,
        ],
    ]);

    $this->assertDatabaseHas('orders', [
        'user_id' => $user->id,
        'whats_item_id' => $whatsItem->id,
        'channel' => 'whatsapp',
        'status' => 'pending',
        'msgs' => 2000,
    ]);
});

test('admin can approve whatsapp order and increment whats item msg_number quota', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create(['role' => 'user']);

    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $restaurant->id,
        'phone' => '01012345678',
        'msg_number' => 100,
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة واتساب', 'en' => 'WhatsApp Package'],
        'msg_number' => 1000,
        'price' => 100,
        'months' => 2,
        'type' => 'whats',
    ]);

    $order = Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'whats_item_id' => $whatsItem->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 100,
        'final_price' => 100,
        'msgs' => 1000,
        'status' => 'pending',
        'channel' => 'whatsapp',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->postJson("/api/admin/orders/{$order->id}/approve");

    $response->assertOk();
    $response->assertJson([
        'status' => true,
        'data' => [
            'order_id' => $order->id,
            'status' => 'approved',
            'channel' => 'whatsapp',
            'activation' => [
                'whats_item_id' => $whatsItem->id,
                'whatsapp_msgs_added' => 1000,
                'msg_number' => 1100,
            ],
        ],
    ]);

    expect($whatsItem->fresh()->msg_number)->toBe(1100);
});

test('admin can manage user whats items and trigger activation methods', function () {
    Http::fake([
        'https://graph.facebook.com/*/meta_pid_555/request_code*' => Http::response([
            'success' => true,
        ], 200),
        'https://graph.facebook.com/*/meta_pid_555/verify_code*' => Http::response([
            'success' => true,
        ], 200),
        'https://graph.facebook.com/*/meta_pid_555/register*' => Http::response([
            'success' => true,
        ], 200),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create([
        'role' => 'user',
        'phone' => '01099998888',
    ]);

    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $restaurant->id,
        'phone' => '01099998888',
        'phone_number_id' => 'meta_pid_555',
        'phone_status' => 'pending_otp',
    ]);

    Sanctum::actingAs($admin);

    // 1. Admin lists user's whats items
    $listResponse = $this->getJson("/api/admin/users/{$restaurant->id}/whats-items");
    $listResponse->assertOk();
    $listResponse->assertJsonCount(1, 'data');

    // 2. Admin calls requestCode via user endpoint
    $codeResponse = $this->postJson("/api/admin/users/{$restaurant->id}/request-code", [
        'code_method' => 'SMS',
    ]);
    $codeResponse->assertOk();

    // 3. Admin calls verifyAndRegister via user endpoint
    $verifyResponse = $this->postJson("/api/admin/users/{$restaurant->id}/verify-and-register", [
        'code' => '123456',
        'pin' => '654321',
    ]);
    $verifyResponse->assertOk();
    expect($whatsItem->fresh()->phone_status)->toBe('active');
});
