<?php

use App\Models\Order;
use App\Models\Package;
use App\Models\Paymob;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->paymob = Paymob::create([
        'title' => 'Paymob Live Gateway',
        'type' => 'test',
        'callback' => 'https://example.com/api/paymob/callback',
        'api_key' => 'TEST_API_KEY_123',
        'iframe_id' => '998877',
        'integration_id' => '112233',
        'Hmac' => 'TEST_HMAC_SECRET',
        'logo' => 'paymob/logo.png',
    ]);

    $this->user = User::factory()->create([
        'role' => 'user',
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '01012345678',
        'facebook_access_token' => 'FB_MOCK_TOKEN',
    ]);

    $this->package = Package::create([
        'name' => ['ar' => 'باقة مميزة', 'en' => 'Premium Package'],
        'type' => 'all',
        'msg_number' => 500,
        'price' => 150.00,
        'months' => 2,
    ]);
});

function fakePaymobHttp(int $paymobOrderId = 987654): void
{
    Http::fake([
        'https://accept.paymob.com/api/auth/tokens' => Http::response([
            'token' => 'MOCK_AUTH_TOKEN_ABC',
        ], 200),
        'https://accept.paymob.com/api/ecommerce/orders' => Http::response([
            'id' => $paymobOrderId,
        ], 200),
        'https://accept.paymob.com/api/acceptance/payment_keys' => Http::response([
            'token' => 'MOCK_PAYMENT_TOKEN_XYZ',
        ], 200),
        'https://graph.facebook.com/*' => Http::response([
            'success' => true,
            'data' => [
                [
                    'id' => 'PAGE_111',
                    'name' => 'My Facebook Page',
                    'access_token' => 'PAGE_TOKEN_111',
                    'instagram_business_account' => [
                        'id' => 'IG_222',
                        'username' => 'my_instagram_page',
                        'name' => 'My Instagram Page',
                    ],
                ],
            ],
        ], 200),
    ]);
}

test('Instagram requestSubscription creates order with faild status and returns Paymob link', function () {
    fakePaymobHttp(778899);
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/user/instagram/orders', [
        'instagram_id' => 'IG_222',
        'package_id' => $this->package->id,
    ]);

    $response->assertCreated()
        ->assertJson([
            'status' => true,
            'data' => [
                'status' => 'faild',
                'channel' => 'instagram',
                'package_id' => $this->package->id,
                'final_price' => 150,
                'payment_url' => 'https://accept.paymob.com/api/acceptance/iframes/998877?payment_token=MOCK_PAYMENT_TOKEN_XYZ',
                'paymob_url' => 'https://accept.paymob.com/api/acceptance/iframes/998877?payment_token=MOCK_PAYMENT_TOKEN_XYZ',
            ],
        ]);

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe('faild');
    expect($order->transaction_id)->toBe('778899');
    expect($order->channel)->toBe('instagram');
});

test('WhatsApp requestSubscription creates order with faild status and returns Paymob link', function () {
    fakePaymobHttp(554433);
    Sanctum::actingAs($this->user);

    $whatsItem = WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '01011112222',
        'phone_status' => 'pending',
    ]);

    $response = $this->postJson('/api/user/whats/orders', [
        'whats_item_id' => $whatsItem->id,
        'package_id' => $this->package->id,
    ]);

    $response->assertCreated()
        ->assertJson([
            'status' => true,
            'data' => [
                'status' => 'faild',
                'whats_item_id' => $whatsItem->id,
                'payment_url' => 'https://accept.paymob.com/api/acceptance/iframes/998877?payment_token=MOCK_PAYMENT_TOKEN_XYZ',
                'paymob_url' => 'https://accept.paymob.com/api/acceptance/iframes/998877?payment_token=MOCK_PAYMENT_TOKEN_XYZ',
            ],
        ]);

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe('faild');
    expect($order->transaction_id)->toBe('554433');
    expect($order->channel)->toBe('whatsapp');
});

test('Messenger requestSubscription creates order with faild status and returns Paymob link', function () {
    fakePaymobHttp(332211);
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/user/messenger/orders', [
        'page_id' => 'PAGE_111',
        'package_id' => $this->package->id,
    ]);

    $response->assertCreated()
        ->assertJson([
            'status' => true,
            'data' => [
                'status' => 'faild',
                'payment_url' => 'https://accept.paymob.com/api/acceptance/iframes/998877?payment_token=MOCK_PAYMENT_TOKEN_XYZ',
                'paymob_url' => 'https://accept.paymob.com/api/acceptance/iframes/998877?payment_token=MOCK_PAYMENT_TOKEN_XYZ',
            ],
        ]);

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe('faild');
    expect($order->transaction_id)->toBe('332211');
    expect($order->channel)->toBe('messenger');
});

test('Paymob callback with success=true approves order by transaction_id and activates subscription', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $whatsItem = WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '01099998888',
        'phone_status' => 'disabled',
        'msg_number' => 10,
    ]);

    $order = Order::create([
        'package_id' => $this->package->id,
        'user_id' => $this->user->id,
        'whats_item_id' => $whatsItem->id,
        'channel' => 'whatsapp',
        'status' => 'faild',
        'transaction_id' => 'PAYMOB_TX_123',
        'price' => 150,
        'final_price' => 150,
        'msgs' => 500,
    ]);

    $response = $this->getJson('/api/paymob/callback?id=PAYMOB_TX_123&success=true&order=999999');

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'order_id' => $order->id,
                'status' => 'approved',
            ],
        ]);

    $order->refresh();
    expect($order->status)->toBe('approved');
    expect($order->from)->not->toBeNull();
    expect($order->to)->not->toBeNull();

    $whatsItem->refresh();
    expect($whatsItem->phone_status)->toBe('active');
    expect($whatsItem->msg_number)->toBe(510);
});

test('Paymob callback with success=false leaves status as faild', function () {
    $order = Order::create([
        'package_id' => $this->package->id,
        'user_id' => $this->user->id,
        'channel' => 'whatsapp',
        'status' => 'faild',
        'transaction_id' => 'PAYMOB_TX_FAILED',
        'price' => 150,
        'final_price' => 150,
        'msgs' => 500,
    ]);

    $response = $this->getJson('/api/paymob/callback?id=PAYMOB_TX_FAILED&success=false&order=888888');

    $response->assertStatus(402)
        ->assertJson([
            'status' => false,
            'data' => [
                'order_id' => $order->id,
                'status' => 'faild',
            ],
        ]);

    $order->refresh();
    expect($order->status)->toBe('faild');
});

test('faild orders do not appear in user pending_orders, history_orders or admin orders list', function () {
    $faildOrder = Order::create([
        'package_id' => $this->package->id,
        'user_id' => $this->user->id,
        'channel' => 'whatsapp',
        'status' => 'faild',
        'transaction_id' => 'TX_HIDDEN',
        'price' => 150,
        'final_price' => 150,
        'msgs' => 500,
    ]);

    $pendingOrder = Order::create([
        'package_id' => $this->package->id,
        'user_id' => $this->user->id,
        'channel' => 'whatsapp',
        'status' => 'pending',
        'price' => 150,
        'final_price' => 150,
        'msgs' => 500,
    ]);

    $approvedOrder = Order::create([
        'package_id' => $this->package->id,
        'user_id' => $this->user->id,
        'channel' => 'whatsapp',
        'status' => 'approved',
        'price' => 150,
        'final_price' => 150,
        'msgs' => 500,
        'from' => now()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
    ]);

    Sanctum::actingAs($this->user);

    // 1. User pending_orders
    $pendingRes = $this->getJson('/api/user/pending_orders');
    $pendingRes->assertOk();
    $pendingIds = collect($pendingRes->json('data.data') ?? $pendingRes->json('data'))->pluck('id')->all();
    expect($pendingIds)->toContain($pendingOrder->id);
    expect($pendingIds)->not->toContain($faildOrder->id);
    expect($pendingIds)->not->toContain($approvedOrder->id);

    // 2. User history_orders
    $historyRes = $this->getJson('/api/user/history_orders');
    $historyRes->assertOk();
    $historyIds = collect($historyRes->json('data.data') ?? $historyRes->json('data'))->pluck('id')->all();
    expect($historyIds)->toContain($approvedOrder->id);
    expect($historyIds)->not->toContain($faildOrder->id);
    expect($historyIds)->not->toContain($pendingOrder->id);

    // 3. Admin orders list (without explicit status filter)
    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);

    $adminRes = $this->getJson('/api/admin/orders');
    $adminRes->assertOk();
    $adminIds = collect($adminRes->json('data.data') ?? $adminRes->json('data'))->pluck('id')->all();
    expect($adminIds)->toContain($pendingOrder->id);
    expect($adminIds)->toContain($approvedOrder->id);
    expect($adminIds)->not->toContain($faildOrder->id);
});
