<?php

use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makePackage(int $months = 1, int $msgs = 100): Package
{
    return Package::create([
        'name' => ['ar' => 'باقة', 'en' => 'Package'],
        'msg_number' => $msgs,
        'price' => 50.00,
        'months' => $months,
    ]);
}

function makePendingMessengerOrder(User $user, Package $package): array
{
    $account = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'status' => 'disabled',
    ]);

    $order = Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 50,
        'final_price' => 50,
        'msgs' => $package->msg_number,
        'status' => 'pending',
        'channel' => 'messenger',
        'messenger_account_id' => $account->id,
        'from' => null,
        'to' => null,
    ]);

    return [$order, $account];
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /admin/orders?status=
// ─────────────────────────────────────────────────────────────────────────────

test('admin can filter orders by status pending', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = makePackage();

    [$order] = makePendingMessengerOrder($user, $package);

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/orders?status=pending');

    $response->assertOk();
    $data = $response->json('data.data');
    expect(collect($data)->pluck('status')->unique()->values()->all())->toBe(['pending']);
});

// ─────────────────────────────────────────────────────────────────────────────
// POST /admin/orders/{order}/approve — Messenger
// ─────────────────────────────────────────────────────────────────────────────

test('admin can approve a pending messenger order and activates page', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = makePackage(months: 1, msgs: 200);

    [$order, $account] = makePendingMessengerOrder($user, $package);

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/orders/{$order->id}/approve");

    $response->assertOk()
        ->assertJson(['status' => true])
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.channel', 'messenger')
        ->assertJsonStructure(['data' => ['from', 'to', 'activation']]);

    // Order updated in DB
    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'approved',
    ]);

    // MessengerAccount activated and msg_number set based on package
    $this->assertDatabaseHas('messenger_accounts', [
        'id' => $account->id,
        'status' => 'active',
        'msg_number' => 200,
    ]);

    // from/to set correctly
    $freshOrder = $order->fresh();
    expect($freshOrder->from->toDateString())->toBe(now()->toDateString());
    expect($freshOrder->to->toDateString())->toBe(now()->addMonth()->toDateString());

    // Graph API subscribe call made
    Http::assertSent(fn ($req) => str_contains($req->url(), 'subscribed_apps'));
});

test('admin can approve a pending messenger order with custom ai_context and ai_file', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = makePackage(months: 2, msgs: 300);

    [$order, $account] = makePendingMessengerOrder($user, $package);

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/orders/{$order->id}/approve", [
            'ai_context' => 'سياق مخصص للمطعم عند الموافقة',
            'ai_file' => 'menus/food_list.json',
        ]);

    $response->assertOk()
        ->assertJson(['status' => true]);

    $freshAccount = $account->fresh();
    expect($freshAccount->status)->toBe('active');
    expect($freshAccount->msg_number)->toBe(300);
    expect($freshAccount->ai_context)->toBe('سياق مخصص للمطعم عند الموافقة');
    expect($freshAccount->ai_file)->toBe('menus/food_list.json');
});

test('cannot approve an already approved order', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = makePackage();

    [$order] = makePendingMessengerOrder($user, $package);
    $order->update(['status' => 'approved', 'from' => now(), 'to' => now()->addMonth()]);

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/orders/{$order->id}/approve");

    $response->assertStatus(409)->assertJson(['status' => false]);
});

// ─────────────────────────────────────────────────────────────────────────────
// POST /admin/orders/{order}/approve — WhatsApp
// ─────────────────────────────────────────────────────────────────────────────

test('admin can approve a pending whatsapp order', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = makePackage(msgs: 500);

    $order = Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 50,
        'final_price' => 50,
        'msgs' => 500,
        'status' => 'pending',
        'channel' => 'whatsapp',
        'from' => null,
        'to' => null,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/orders/{$order->id}/approve");

    $response->assertOk()->assertJson(['status' => true]);
});

// ─────────────────────────────────────────────────────────────────────────────
// POST /admin/orders/{order}/reject
// ─────────────────────────────────────────────────────────────────────────────

test('admin can reject a pending order with optional reason', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = makePackage();

    [$order, $account] = makePendingMessengerOrder($user, $package);

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/orders/{$order->id}/reject", [
            'reason' => 'Invalid page token.',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.reason', 'Invalid page token.');

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'rejected',
    ]);

    // MessengerAccount stays as disabled (not deleted)
    $this->assertDatabaseHas('messenger_accounts', [
        'id' => $account->id,
        'status' => 'disabled',
    ]);
});

test('cannot reject an already rejected order', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = makePackage();

    [$order] = makePendingMessengerOrder($user, $package);
    $order->update(['status' => 'rejected']);

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/orders/{$order->id}/reject");

    $response->assertStatus(409)->assertJson(['status' => false]);
});
