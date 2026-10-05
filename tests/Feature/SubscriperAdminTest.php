<?php

use App\Models\Discount;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\Tax;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can list subscribers with renewal date and calculated expected price considering discount validity at renewal date', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user', 'name' => 'John Doe']);

    // Create a discount valid between 2026-10-01 and 2026-10-15
    $discount = Discount::create([
        'name' => 'Early Bird 20%',
        'amount' => 20,
        'type' => 'percentage',
        'from' => '2026-10-01',
        'to' => '2026-10-15',
    ]);

    // Create tax 10%
    $tax = Tax::create([
        'name' => 'VAT 10%',
        'amount' => 10,
        'type' => 'percentage',
    ]);

    // Package A: price 100, discount 20%, tax 10%
    $packageA = Package::create([
        'name' => ['ar' => 'باقة أ', 'en' => 'Package A'],
        'msg_number' => 500,
        'price' => 100.00,
        'discount_id' => $discount->id,
        'tax_id' => $tax->id,
        'months' => 1,
    ]);

    // Package B: price 200, no discount
    $packageB = Package::create([
        'name' => ['ar' => 'باقة ب', 'en' => 'Package B'],
        'msg_number' => 1000,
        'price' => 200.00,
        'months' => 1,
    ]);

    // WhatsApp Item for user
    $whatsItem = WhatsItem::create([
        'user_id' => $user->id,
        'phone' => '201234567890',
        'phone_status' => 'active',
        'msg_number' => 150,
        'start_date' => '2026-09-10',
        'end_date' => '2026-10-10',
    ]);

    // Messenger Account for user
    $messengerAccount = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_123',
        'page_name' => 'My Restaurant Page',
        'status' => 'active',
        'msg_number' => 250,
        'start_date' => '2026-09-25',
        'end_date' => '2026-10-25',
    ]);

    // Order 1: WhatsApp (Package A), ends on 2026-10-10 -> within discount period!
    Order::create([
        'package_id' => $packageA->id,
        'user_id' => $user->id,
        'total_discount' => 20,
        'total_tax' => 8,
        'price' => 100,
        'final_price' => 88,
        'from' => '2026-09-10',
        'to' => '2026-10-10',
        'msgs' => 500,
        'status' => 'approved',
        'channel' => 'whatsapp',
        'whats_item_id' => $whatsItem->id,
    ]);

    // Order 2: Messenger (Package B), ends on 2026-10-25
    Order::create([
        'package_id' => $packageB->id,
        'user_id' => $user->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 200,
        'final_price' => 200,
        'from' => '2026-09-25',
        'to' => '2026-10-25',
        'msgs' => 1000,
        'status' => 'approved',
        'channel' => 'messenger',
        'messenger_account_id' => $messengerAccount->id,
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/subscripers');

    $response->assertOk()
        ->assertJson(['status' => true]);

    $data = $response->json('data.data');
    expect($data)->toHaveCount(1);

    $subscriber = $data[0];
    expect($subscriber['id'])->toBe($user->id);
    expect($subscriber['available_msgs'])->toBe(400); // 150 + 250
    expect($subscriber['renewal_date'])->toBe('2026-10-10'); // First order needing renewal (2026-10-10 < 2026-10-25)
    expect($subscriber['next_renewal_date'])->toBe('2026-10-10');

    // Expected amount for Package A on 2026-10-10:
    // Base: 100, Discount at 2026-10-10: 20% = 20, Tax: 10% of 80 = 8. Final: 88.00
    expect((float) $subscriber['expected_amount'])->toBe(88.0);
    expect((float) $subscriber['next_order_price'])->toBe(88.0);
    expect($subscriber['next_order']['channel'])->toBe('whatsapp');
});

test('discount is NOT applied if renewal date is after discount end date', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);

    // Discount expired on 2026-10-05
    $discount = Discount::create([
        'name' => 'Expired Promo',
        'amount' => 50,
        'type' => 'percentage',
        'from' => '2026-09-01',
        'to' => '2026-10-05',
    ]);

    $tax = Tax::create([
        'name' => 'Tax 14%',
        'amount' => 14,
        'type' => 'percentage',
    ]);

    $package = Package::create([
        'name' => ['en' => 'Standard Package'],
        'msg_number' => 200,
        'price' => 100.00,
        'discount_id' => $discount->id,
        'tax_id' => $tax->id,
        'months' => 1,
    ]);

    $whatsItem = WhatsItem::create([
        'user_id' => $user->id,
        'phone' => '201111111111',
        'phone_status' => 'active',
        'msg_number' => 50,
        'start_date' => '2026-09-20',
        'end_date' => '2026-10-20',
    ]);

    // Order ends on 2026-10-20 (after discount expired on 2026-10-05)
    Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'total_discount' => 0,
        'total_tax' => 14,
        'price' => 100,
        'final_price' => 114,
        'from' => '2026-09-20',
        'to' => '2026-10-20',
        'msgs' => 200,
        'status' => 'approved',
        'channel' => 'whatsapp',
        'whats_item_id' => $whatsItem->id,
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/subscripers');

    $response->assertOk();
    $subscriber = $response->json('data.data.0');

    expect($subscriber['renewal_date'])->toBe('2026-10-20');
    // On 2026-10-20, discount is expired, so discount = 0, tax = 14% on 100 = 14, final = 114.00
    expect((float) $subscriber['expected_amount'])->toBe(114.0);
    expect((float) $subscriber['next_order']['total_discount'])->toBe(0.0);
    expect((float) $subscriber['next_order']['total_tax'])->toBe(14.0);
});

test('admin can fetch single subscriber with full details of messengerAccounts, whatsItems, and instagramItems', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user', 'name' => 'Subscriber Single']);

    $package = Package::create([
        'name' => ['en' => 'Pro Package'],
        'msg_number' => 1000,
        'price' => 300.00,
        'months' => 1,
    ]);

    $messenger = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_single_1',
        'page_name' => 'Burger House Page',
        'status' => 'active',
        'msg_number' => 80,
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-01',
    ]);

    $whats = WhatsItem::create([
        'user_id' => $user->id,
        'phone' => '201099999999',
        'phone_status' => 'active',
        'msg_number' => 120,
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-01',
    ]);

    $instagram = InstagramItem::factory()->create([
        'user_id' => $user->id,
        'username' => 'burger_insta',
        'name' => 'Burger Insta Account',
        'status' => 'active',
        'msg_number' => 200,
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-01',
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 300,
        'final_price' => 300,
        'from' => '2026-10-01',
        'to' => '2026-11-01',
        'msgs' => 1000,
        'status' => 'approved',
        'channel' => 'whatsapp',
        'whats_item_id' => $whats->id,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/admin/subscripers/{$user->id}");

    $response->assertOk()
        ->assertJson(['status' => true]);

    $userData = $response->json('data');
    expect($userData['id'])->toBe($user->id);
    expect($userData['name'])->toBe('Subscriber Single');
    expect($userData['available_msgs'])->toBe(400); // 80 + 120 + 200
    expect($userData['renewal_date'])->toBe('2026-11-01');
    expect((float) $userData['expected_amount'])->toBe(300.0);

    // Verify messengerAccounts
    expect($userData['messengerAccounts'])->toHaveCount(1);
    $m = $userData['messengerAccounts'][0];
    expect($m['name'])->toBe('Burger House Page');
    expect($m['page_name'])->toBe('Burger House Page');
    expect($m['available_msgs'])->toBe(80);
    expect($m['from'])->toBe('2026-10-01');
    expect($m['to'])->toBe('2026-11-01');
    expect($m['renewal_date'])->toBe('2026-11-01');
    expect($m['is_subscribed'])->toBeTrue();

    // Verify whatsItems
    expect($userData['whatsItems'])->toHaveCount(1);
    $w = $userData['whatsItems'][0];
    expect($w['phone'])->toBe('201099999999');
    expect($w['available_msgs'])->toBe(120);
    expect($w['from'])->toBe('2026-10-01');
    expect($w['to'])->toBe('2026-11-01');
    expect($w['renewal_date'])->toBe('2026-11-01');
    expect($w['is_subscribed'])->toBeTrue();

    // Verify instagramItems
    expect($userData['instagramItems'])->toHaveCount(1);
    $i = $userData['instagramItems'][0];
    expect($i['username'])->toBe('burger_insta');
    expect($i['available_msgs'])->toBe(200);
    expect($i['from'])->toBe('2026-10-01');
    expect($i['to'])->toBe('2026-11-01');
    expect($i['renewal_date'])->toBe('2026-11-01');
    expect($i['is_subscribed'])->toBeTrue();
});

test('subscripers endpoint supports channel filter', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $userWhats = User::factory()->create(['role' => 'user']);
    WhatsItem::create([
        'user_id' => $userWhats->id,
        'phone' => '201011111111',
        'phone_status' => 'active',
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-01',
    ]);

    $userMessenger = User::factory()->create(['role' => 'user']);
    MessengerAccount::factory()->create([
        'user_id' => $userMessenger->id,
        'page_id' => 'page_m_1',
        'page_name' => 'Page Messenger Only',
        'status' => 'active',
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-01',
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/subscripers?channel=whatsapp');

    $response->assertOk();
    $ids = collect($response->json('data.data'))->pluck('id')->all();
    expect($ids)->toContain($userWhats->id);
    expect($ids)->not->toContain($userMessenger->id);
});

test('picks the earliest active order among the latest orders per item and ignores already renewed orders', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);

    $package = Package::create([
        'name' => ['en' => 'Package'],
        'msg_number' => 500,
        'price' => 150.00,
        'months' => 1,
    ]);

    $whatsItem = WhatsItem::create([
        'user_id' => $user->id,
        'phone' => '201022222222',
        'phone_status' => 'active',
        'start_date' => '2026-08-01',
        'end_date' => '2026-11-15',
    ]);

    $messengerAccount = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_m_2',
        'page_name' => 'Messenger Page',
        'status' => 'active',
        'start_date' => '2026-09-25',
        'end_date' => '2026-10-25',
    ]);

    // WhatsItem had an old order ending 2026-09-01
    Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'price' => 150,
        'final_price' => 150,
        'from' => '2026-08-01',
        'to' => '2026-09-01',
        'status' => 'approved',
        'channel' => 'whatsapp',
        'whats_item_id' => $whatsItem->id,
    ]);

    // WhatsItem was renewed by an order ending 2026-11-15
    Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'price' => 150,
        'final_price' => 150,
        'from' => '2026-09-01',
        'to' => '2026-11-15',
        'status' => 'approved',
        'channel' => 'whatsapp',
        'whats_item_id' => $whatsItem->id,
    ]);

    // Messenger order ending 2026-10-25
    Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'price' => 150,
        'final_price' => 150,
        'from' => '2026-09-25',
        'to' => '2026-10-25',
        'status' => 'approved',
        'channel' => 'messenger',
        'messenger_account_id' => $messengerAccount->id,
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/admin/subscripers');

    $response->assertOk();
    $subscriber = $response->json('data.data.0');

    // Earliest active renewal should be Messenger on 2026-10-25 (since WhatsApp was renewed to 2026-11-15)
    expect($subscriber['renewal_date'])->toBe('2026-10-25');
    expect($subscriber['next_order']['channel'])->toBe('messenger');
});

test('subscriper single endpoint is accessible via /admin/subscriper/{id} and /admin/subscriper?user_id={id}', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);

    WhatsItem::create([
        'user_id' => $user->id,
        'phone' => '201033333333',
        'phone_status' => 'active',
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-01',
    ]);

    $res1 = $this->actingAs($admin)->getJson("/api/admin/subscriper/{$user->id}");
    $res1->assertOk()->assertJsonPath('data.id', $user->id);

    $res2 = $this->actingAs($admin)->getJson("/api/admin/subscriper?user_id={$user->id}");
    $res2->assertOk()->assertJsonPath('data.id', $user->id);
});

test('non-admin cannot access subscriber endpoints', function () {
    $user = User::factory()->create(['role' => 'user']);

    $response = $this->actingAs($user)->getJson('/api/admin/subscripers');
    $response->assertForbidden();

    $response2 = $this->actingAs($user)->getJson("/api/admin/subscripers/{$user->id}");
    $response2->assertForbidden();
});
