<?php

use App\Models\EcommerceOrder;
use App\Models\User;
use App\Models\WhatsItem;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.meta.waba_id' => 'WABA_TEST_123',
        'services.meta.system_user_token' => 'SYSTEM_TOKEN_XYZ',
        'services.meta.phone_number_id' => 'PNID_123',
    ]);
});

function createStoreUser(): User
{
    return User::factory()->create([
        'role' => 'user',
        'restuarant_name' => 'مطعم البيت الكبير',
    ]);
}

function createStoreWhatsItem(User $user, array $overrides = []): WhatsItem
{
    return WhatsItem::factory()->create(array_merge([
        'user_id' => $user->id,
        'phone' => '+201012345678',
        'phone_number_id' => 'phone_id_999',
        'waba_id' => 'waba_id_888',
        'access_token' => 'TOKEN_123',
        'phone_status' => 'active',
        'msg_number' => 100,
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'order_confirmation_enabled' => true,
        'ecommerce_platform' => 'woocommerce',
        'store_webhook_token' => 'test_token_abc123',
        'website_url' => 'https://my-awesome-store.com',
        'user_name' => 'ck_test_key_123',
        'password' => 'cs_test_secret_456',
    ], $overrides));
}

test('phone normalizer handles Egyptian format variations and defaults to +20', function () {
    /** @var MetaWhatsAppService $service */
    $service = app(MetaWhatsAppService::class);

    expect($service->normalizeRecipientPhone('01012345678'))->toBe('201012345678')
        ->and($service->normalizeRecipientPhone('1012345678'))->toBe('201012345678')
        ->and($service->normalizeRecipientPhone('+201012345678'))->toBe('201012345678')
        ->and($service->normalizeRecipientPhone('201012345678'))->toBe('201012345678')
        ->and($service->normalizeRecipientPhone('01155566677'))->toBe('201155566677')
        ->and($service->normalizeRecipientPhone('01200000000'))->toBe('201200000000')
        ->and($service->normalizeRecipientPhone('01599999999'))->toBe('201599999999')
        ->and($service->normalizeRecipientPhone('0501234567'))->toBe('966501234567')
        ->and($service->normalizeRecipientPhone('+966501234567'))->toBe('966501234567');
});

test('order_confirmation_enabled is exclusive per user when updated via API', function () {
    $user = createStoreUser();
    $item1 = createStoreWhatsItem($user, ['order_confirmation_enabled' => true]);
    $item2 = createStoreWhatsItem($user, ['order_confirmation_enabled' => false, 'store_webhook_token' => 'tok2']);

    $response = $this->actingAs($user)
        ->putJson("/api/user/whats/items/{$item2->id}", [
            'order_confirmation_enabled' => true,
        ]);

    $response->assertStatus(200);

    expect($item1->fresh()->order_confirmation_enabled)->toBeFalse()
        ->and($item2->fresh()->order_confirmation_enabled)->toBeTrue();
});

test('user can save shopify client_id and secret via API and retrieve them', function () {
    $user = createStoreUser();
    $item = createStoreWhatsItem($user, ['order_confirmation_enabled' => false]);

    $response = $this->actingAs($user)
        ->putJson("/api/user/whats/items/{$item->id}", [
            'ecommerce_platform' => 'shopify',
            'client_id' => 'shopify_client_id_123',
            'secret' => 'shopify_secret_456',
            'order_confirmation_enabled' => true,
        ]);

    $response->assertStatus(200);

    expect($item->fresh()->user_name)->toBe('shopify_client_id_123')
        ->and($item->fresh()->password)->toBe('shopify_secret_456')
        ->and($item->fresh()->ecommerce_platform)->toBe('shopify');

    $listResponse = $this->actingAs($user)
        ->getJson('/api/user/whats/items');

    $listResponse->assertStatus(200)
        ->assertJsonFragment([
            'client_id' => 'shopify_client_id_123',
            'client_secret' => 'shopify_secret_456',
        ]);
});

test('user can connect shopify directly using connect-shopify endpoint', function () {
    $user = createStoreUser();
    $item = createStoreWhatsItem($user);

    Http::fake([
        'mystore.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpca_live_token_777']),
        'mystore.myshopify.com/admin/api/2024-01/shop.json' => Http::response([
            'shop' => [
                'name' => 'متجر الأحذية العصري',
                'email' => 'store@example.com',
                'currency' => 'EGP',
            ],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/user/whats/items/{$item->id}/connect-shopify", [
            'website_url' => 'mystore.myshopify.com',
            'client_id' => 'my_client_id_abc',
            'secret' => 'my_secret_xyz',
            'order_confirmation_enabled' => true,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'message' => 'Shopify store connected successfully.',
            'shopify' => [
                'access_token' => 'shpca_live_token_777',
            ],
        ]);

    expect($item->fresh()->shopify_access_token)->toBe('shpca_live_token_777')
        ->and($item->fresh()->ecommerce_platform)->toBe('shopify')
        ->and($item->fresh()->order_confirmation_enabled)->toBeTrue();
});

test('woocommerce webhook creates ecommerce order and dispatches template with approve and reject buttons', function () {
    $user = createStoreUser();
    $whatsItem = createStoreWhatsItem($user);

    Http::fake([
        'graph.facebook.com/*/message_templates*' => Http::response(['data' => [['name' => 'order_confirmation', 'language' => 'ar', 'id' => 'tmpl_1']]]),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.order_confirm_001']]]),
    ]);

    $payload = [
        'id' => 1045,
        'number' => '1045',
        'status' => 'pending',
        'total' => '250.00',
        'currency' => 'EGP',
        'billing' => [
            'first_name' => 'أحمد',
            'last_name' => 'علي',
            'phone' => '01012345678',
        ],
    ];

    $response = $this->postJson("/api/webhooks/store/{$whatsItem->store_webhook_token}", $payload, [
        'X-WC-Webhook-Topic' => 'order.created',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'message' => 'Order processed successfully.',
        ]);

    $order = EcommerceOrder::where('external_order_id', '1045')->first();
    expect($order)->not->toBeNull()
        ->and($order->customer_name)->toBe('أحمد علي')
        ->and($order->customer_phone)->toBe('01012345678')
        ->and((float) $order->total_price)->toBe(250.00)
        ->and($order->status)->toBe('pending')
        ->and($order->meta_message_id)->toBe('wamid.order_confirm_001');

    Http::assertSent(function (Request $request) use ($order) {
        if (! str_contains($request->url(), '/messages')) {
            return false;
        }

        $body = $request->data();
        $components = $body['template']['components'] ?? [];

        $hasApproveButton = false;
        $hasRejectButton = false;

        foreach ($components as $component) {
            if (($component['type'] ?? '') === 'button') {
                $payload = $component['parameters'][0]['payload'] ?? '';
                if ($payload === "ORDER_APPROVE_{$order->id}") {
                    $hasApproveButton = true;
                }
                if ($payload === "ORDER_REJECT_{$order->id}") {
                    $hasRejectButton = true;
                }
            }
        }

        return $body['to'] === '201012345678'
            && $body['template']['name'] === 'order_confirmation'
            && $hasApproveButton
            && $hasRejectButton;
    });
});

test('shopify webhook creates ecommerce order successfully', function () {
    $user = createStoreUser();
    $whatsItem = createStoreWhatsItem($user, [
        'ecommerce_platform' => 'shopify',
        'shopify_access_token' => 'shpat_test_123',
    ]);

    Http::fake([
        'graph.facebook.com/*/message_templates*' => Http::response(['data' => []]),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.shopify_001']]]),
    ]);

    $payload = [
        'id' => 889911,
        'order_number' => 502,
        'current_total_price' => '450.00',
        'currency' => 'EGP',
        'customer' => [
            'first_name' => 'محمود',
            'last_name' => 'إبراهيم',
            'phone' => '01234567890',
        ],
    ];

    $response = $this->postJson("/api/webhooks/store/{$whatsItem->store_webhook_token}", $payload, [
        'X-Shopify-Topic' => 'orders/create',
    ]);

    $response->assertStatus(200);

    $order = EcommerceOrder::where('external_order_id', '889911')->first();
    expect($order)->not->toBeNull()
        ->and($order->customer_name)->toBe('محمود إبراهيم')
        ->and($order->order_number)->toBe('502')
        ->and((float) $order->total_price)->toBe(450.00);
});

test('whatsapp button approve marks ecommerce order confirmed and updates woocommerce order to processing', function () {
    $user = createStoreUser();
    $whatsItem = createStoreWhatsItem($user);

    $order = EcommerceOrder::create([
        'user_id' => $user->id,
        'whats_item_id' => $whatsItem->id,
        'platform' => 'woocommerce',
        'external_order_id' => '1045',
        'order_number' => '1045',
        'customer_name' => 'أحمد علي',
        'customer_phone' => '201012345678',
        'total_price' => 250.00,
        'status' => 'pending',
    ]);

    Http::fake([
        'my-awesome-store.com/wp-json/wc/v3/orders/1045' => Http::response(['id' => 1045, 'status' => 'processing']),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.reply_001']]]),
    ]);

    $webhookPayload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'entry_1',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'metadata' => [
                                'display_phone_number' => '201012345678',
                                'phone_number_id' => $whatsItem->phone_number_id,
                            ],
                            'contacts' => [
                                ['profile' => ['name' => 'أحمد علي']],
                            ],
                            'messages' => [
                                [
                                    'from' => '201012345678',
                                    'id' => 'wamid.user_btn_click_1',
                                    'type' => 'button',
                                    'button' => [
                                        'payload' => "ORDER_APPROVE_{$order->id}",
                                        'text' => 'تأكيد الطلب',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/web-hook', $webhookPayload);

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'order_action_processed',
            'action' => 'approved',
            'order_id' => $order->id,
        ]);

    expect($order->fresh()->status)->toBe('confirmed')
        ->and($order->fresh()->confirmed_at)->not->toBeNull();

    // Verify WooCommerce API was called with status = processing
    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/wp-json/wc/v3/orders/1045')
            && ($request->data()['status'] ?? null) === 'processing';
    });
});

test('whatsapp button reject marks ecommerce order cancelled and updates woocommerce order to cancelled', function () {
    $user = createStoreUser();
    $whatsItem = createStoreWhatsItem($user);

    $order = EcommerceOrder::create([
        'user_id' => $user->id,
        'whats_item_id' => $whatsItem->id,
        'platform' => 'woocommerce',
        'external_order_id' => '1046',
        'order_number' => '1046',
        'customer_name' => 'سارة محمد',
        'customer_phone' => '201100000000',
        'total_price' => 120.00,
        'status' => 'pending',
    ]);

    Http::fake([
        'my-awesome-store.com/wp-json/wc/v3/orders/1046' => Http::response(['id' => 1046, 'status' => 'cancelled']),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.reply_002']]]),
    ]);

    $webhookPayload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'entry_1',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'metadata' => [
                                'display_phone_number' => '201012345678',
                                'phone_number_id' => $whatsItem->phone_number_id,
                            ],
                            'contacts' => [
                                ['profile' => ['name' => 'سارة محمد']],
                            ],
                            'messages' => [
                                [
                                    'from' => '201100000000',
                                    'id' => 'wamid.user_btn_click_2',
                                    'type' => 'button',
                                    'button' => [
                                        'payload' => "ORDER_REJECT_{$order->id}",
                                        'text' => 'إلغاء الطلب',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/web-hook', $webhookPayload);

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'order_action_processed',
            'action' => 'rejected',
            'order_id' => $order->id,
        ]);

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($order->fresh()->cancelled_at)->not->toBeNull();

    // Verify WooCommerce API was called with status = cancelled
    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/wp-json/wc/v3/orders/1046')
            && ($request->data()['status'] ?? null) === 'cancelled';
    });
});

test('user can list and inspect their ecommerce orders via API', function () {
    $user = createStoreUser();
    $whatsItem = createStoreWhatsItem($user);

    $order1 = EcommerceOrder::create([
        'user_id' => $user->id,
        'whats_item_id' => $whatsItem->id,
        'platform' => 'woocommerce',
        'external_order_id' => '101',
        'customer_name' => 'عميل 1',
        'customer_phone' => '201000000001',
        'status' => 'pending',
    ]);

    $order2 = EcommerceOrder::create([
        'user_id' => $user->id,
        'whats_item_id' => $whatsItem->id,
        'platform' => 'woocommerce',
        'external_order_id' => '102',
        'customer_name' => 'عميل 2',
        'customer_phone' => '201000000002',
        'status' => 'confirmed',
    ]);

    $listResponse = $this->actingAs($user)
        ->getJson('/api/user/ecommerce-orders');

    $listResponse->assertStatus(200)
        ->assertJsonPath('data.total', 2);

    $showResponse = $this->actingAs($user)
        ->getJson("/api/user/ecommerce-orders/{$order1->id}");

    $showResponse->assertStatus(200)
        ->assertJsonPath('data.id', $order1->id)
        ->assertJsonPath('data.customer_name', 'عميل 1');
});
