<?php

use App\Events\InstagramEvent;
use App\Events\TypingEvent;
use App\Models\Chat;
use App\Models\InstagramItem;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Admin Instagram Item CRUD
// ─────────────────────────────────────────────────────────────────────────────

test('admin can list, create, view, update, regenerate token, and delete instagram items', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($admin);

    // Create
    $createResponse = $this->postJson("/api/admin/users/{$user->id}/instagram-items", [
        'instagram_id' => '17841400012345678',
        'username' => 'test_restaurant_ig',
        'name' => 'Test Restaurant IG',
        'page_id' => '100012345678901',
        'access_token' => 'EAA_test_token_admin',
        'status' => 'active',
        'msg_number' => 500,
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.username', 'test_restaurant_ig');

    $itemId = $createResponse->json('data.id');

    // List
    $listResponse = $this->getJson("/api/admin/users/{$user->id}/instagram-items");
    $listResponse->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonCount(1, 'data');

    // Show
    $showResponse = $this->getJson("/api/admin/users/{$user->id}/instagram-items/{$itemId}");
    $showResponse->assertOk()
        ->assertJsonPath('data.id', $itemId);

    // Update
    $updateResponse = $this->putJson("/api/admin/users/{$user->id}/instagram-items/{$itemId}", [
        'name' => 'Updated IG Restaurant',
        'msg_number' => 800,
    ]);
    $updateResponse->assertOk()
        ->assertJsonPath('data.name', 'Updated IG Restaurant')
        ->assertJsonPath('data.msg_number', 800);

    // Regenerate verify token
    $oldToken = InstagramItem::find($itemId)->verify_token;
    $regenResponse = $this->postJson("/api/admin/users/{$user->id}/instagram-items/{$itemId}/regenerate-token");
    $regenResponse->assertOk()
        ->assertJsonPath('status', true);
    $newToken = InstagramItem::find($itemId)->verify_token;
    expect($newToken)->not->toBe($oldToken);

    // Delete
    $deleteResponse = $this->deleteJson("/api/admin/users/{$user->id}/instagram-items/{$itemId}");
    $deleteResponse->assertOk();
    $this->assertDatabaseMissing('instagram_items', ['id' => $itemId]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. User Instagram Self-Service
// ─────────────────────────────────────────────────────────────────────────────

test('user can fetch instagram packages, accounts from Meta Graph API, request subscription and update ai data', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'facebook_access_token' => 'EAAG_fake_user_fb_token',
    ]);
    Sanctum::actingAs($user);

    // Packages
    Package::factory()->create(['name' => ['ar' => 'باقة انستغرام', 'en' => 'Instagram Plan'], 'type' => 'instagram', 'msg_number' => 300, 'price' => 100]);
    Package::factory()->create(['name' => ['ar' => 'باقة شاملة', 'en' => 'All Plan'], 'type' => 'all', 'msg_number' => 1000, 'price' => 300]);
    Package::factory()->create(['name' => ['ar' => 'باقة واتساب', 'en' => 'WhatsApp Plan'], 'type' => 'whats']);

    $packagesResponse = $this->getJson('/api/user/instagram/packages');
    $packagesResponse->assertOk()
        ->assertJsonCount(2, 'data'); // Only 'instagram' and 'all'

    // Mock Graph API accounts response
    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                [
                    'id' => 'page_123',
                    'name' => 'Food Page',
                    'access_token' => 'EAA_page_token_123',
                    'instagram_business_account' => [
                        'id' => '17841400099999999',
                        'username' => 'food_burger_ig',
                        'name' => 'Food Burger Instagram',
                        'profile_picture_url' => 'https://via.placeholder.com/150',
                    ],
                ],
                [
                    'id' => 'page_456',
                    'name' => 'Page Without Instagram',
                    'access_token' => 'EAA_page_token_456',
                ],
            ],
        ], 200),
    ]);

    $accountsResponse = $this->getJson('/api/user/instagram/accounts');
    $accountsResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.instagram_id', '17841400099999999')
        ->assertJsonPath('data.0.username', 'food_burger_ig');

    // Request subscription (zero manual data entry)
    $pkg = Package::where('type', 'instagram')->first();
    $subResponse = $this->postJson('/api/user/instagram/orders', [
        'package_id' => $pkg->id,
        'page_id' => 'page_123',
        'instagram_id' => '17841400099999999',
        'username' => 'food_burger_ig',
        'name' => 'Food Burger Instagram',
        'profile_picture_url' => 'https://via.placeholder.com/150',
    ]);

    $subResponse->assertCreated()
        ->assertJsonPath('status', true);

    $this->assertDatabaseHas('instagram_items', [
        'user_id' => $user->id,
        'instagram_id' => '17841400099999999',
        'status' => 'disabled',
    ]);

    $this->assertDatabaseHas('orders', [
        'user_id' => $user->id,
        'channel' => 'instagram',
        'status' => 'pending',
    ]);

    // Update AI Data
    $igItem = InstagramItem::where('instagram_id', '17841400099999999')->first();
    $aiResponse = $this->postJson('/api/user/instagram/ai_data', [
        'instagram_item_id' => $igItem->id,
        'ai_context' => 'أنت مساعد انستغرام لمطعم برجر مميز.',
        'android_link' => 'https://play.google.com/store/apps/details?id=com.burger',
        'website_url' => 'https://burger.example.com',
    ]);

    $aiResponse->assertOk()
        ->assertJsonPath('data.ai_context', 'أنت مساعد انستغرام لمطعم برجر مميز.')
        ->assertJsonPath('data.website_url', 'https://burger.example.com');

    // List items
    $itemsResponse = $this->getJson('/api/user/instagram/items');
    $itemsResponse->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Admin Approving Instagram Order
// ─────────────────────────────────────────────────────────────────────────────

test('admin approving instagram order activates instagram item and assigns messages quota', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);

    $item = InstagramItem::factory()->create([
        'user_id' => $user->id,
        'status' => 'disabled',
        'msg_number' => 0,
    ]);

    $package = Package::factory()->create([
        'type' => 'instagram',
        'msg_number' => 500,
        'months' => 2,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'package_id' => $package->id,
        'instagram_item_id' => $item->id,
        'channel' => 'instagram',
        'status' => 'pending',
        'msgs' => 500,
        'price' => 100,
        'final_price' => 100,
        'total_discount' => 0,
        'total_tax' => 0,
    ]);

    Sanctum::actingAs($admin);

    $approveResponse = $this->postJson("/api/admin/orders/{$order->id}/approve");
    $approveResponse->assertOk()
        ->assertJsonPath('status', true);

    $order->refresh();
    $item->refresh();

    expect($order->status)->toBe('approved');
    expect($order->from)->not->toBeNull();
    expect($order->to)->not->toBeNull();
    expect($item->status)->toBe('active');
    expect($item->msg_number)->toBe(500);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Instagram Webhook (Verification & Auto-Reply)
// ─────────────────────────────────────────────────────────────────────────────

test('instagram webhook verifies correctly with per-item verify_token', function () {
    $item = InstagramItem::factory()->create([
        'verify_token' => 'ig-verify-token-xyz',
    ]);

    $response = $this->get(
        '/api/instagram-webhook?hub_mode=subscribe&hub_verify_token=ig-verify-token-xyz&hub_challenge=IG_CHALLENGE_123'
    );

    $response->assertOk();
    expect($response->getContent())->toBe('IG_CHALLENGE_123');
});

test('instagram webhook verifies correctly with app-level verify_token', function () {
    config(['services.meta.instagram_verify_token' => 'ig-app-token-999']);

    $response = $this->get(
        '/api/instagram-webhook?hub_mode=subscribe&hub_verify_token=ig-app-token-999&hub_challenge=IG_APP_OK'
    );

    $response->assertOk();
    expect($response->getContent())->toBe('IG_APP_OK');
});

test('instagram webhook fails with invalid token', function () {
    config(['services.meta.instagram_verify_token' => 'secret-ig']);

    $response = $this->get(
        '/api/instagram-webhook?hub_mode=subscribe&hub_verify_token=wrong-token&hub_challenge=IG_APP_FAIL'
    );

    $response->assertForbidden();
});

test('instagram webhook ignores non-instagram object', function () {
    $response = $this->postJson('/api/instagram-webhook', [
        'object' => 'page',
        'entry' => [],
    ]);

    $response->assertOk()->assertJson(['status' => 'ignored_non_instagram']);
});

test('instagram webhook handles message, queries OpenAI, sends reply and stores chats & msg_sends', function () {
    Event::fake([InstagramEvent::class, TypingEvent::class]);

    Http::fake([
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => 'IG_USER_12345',
            'message_id' => 'mid.ig_reply_test_456',
        ], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_ig_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'أهلاً بك في مطعمنا على انستغرام! كيف أقدر أساعدك؟',
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->withQuota(100)->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841400088888888',
        'access_token' => 'EAA_test_ig_access_token',
        'android_link' => 'https://play.google.com/test_app',
    ]);

    $payload = [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => '17841400088888888',
                'time' => 1700000000,
                'messaging' => [
                    [
                        'sender' => ['id' => 'IG_USER_12345'],
                        'recipient' => ['id' => '17841400088888888'],
                        'message' => [
                            'mid' => 'mid.ig_cust_001',
                            'text' => 'ممكن تفاصيل المنيو؟',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/instagram-webhook', $payload);

    $response->assertOk()
        ->assertJson([
            'status' => 'success',
            'instagram_sent' => true,
        ]);

    // Check quota decrement
    $item->refresh();
    expect($item->msg_number)->toBe(99);

    // Customer message in chats
    $this->assertDatabaseHas('chats', [
        'user_id' => $restaurant->id,
        'instagram_item_id' => $item->id,
        'channel' => 'instagram',
        'instagram_sender_id' => 'IG_USER_12345',
        'message' => 'ممكن تفاصيل المنيو؟',
        'is_admin' => false,
        'sender_type' => 'customer',
    ]);

    // Bot reply in chats
    $this->assertDatabaseHas('chats', [
        'user_id' => $restaurant->id,
        'instagram_item_id' => $item->id,
        'channel' => 'instagram',
        'instagram_sender_id' => 'IG_USER_12345',
        'is_admin' => true,
        'sender_type' => 'bot',
    ]);

    // MsgSend record
    $this->assertDatabaseHas('msg_sends', [
        'user_id' => $restaurant->id,
        'instagram_item_id' => $item->id,
        'channel' => 'instagram',
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. User Instagram Chat & Inbox
// ─────────────────────────────────────────────────────────────────────────────

test('user can view instagram accounts, conversations, messages, send manual reply and mark as read', function () {
    $user = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->withQuota(50)->create([
        'user_id' => $user->id,
        'username' => 'resto_official',
    ]);

    // Create a customer chat
    Chat::create([
        'user_id' => $user->id,
        'instagram_item_id' => $item->id,
        'name' => 'Customer A',
        'message' => 'Hello Instagram!',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
        'channel' => 'instagram',
        'instagram_sender_id' => 'CUST_IG_999',
    ]);

    Sanctum::actingAs($user);

    // 1. Accounts
    $accountsRes = $this->getJson('/api/user/chat/instagram/accounts');
    $accountsRes->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.unread_count', 1);

    // 2. Conversations
    $convsRes = $this->getJson("/api/user/chat/instagram/conversations?instagram_item_id={$item->id}");
    $convsRes->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sender_id', 'CUST_IG_999');

    // 3. Messages (marks unread)
    $msgsRes = $this->getJson("/api/user/chat/instagram/messages?instagram_item_id={$item->id}&sender_id=CUST_IG_999");
    $msgsRes->assertOk()
        ->assertJsonCount(1, 'data');

    $this->assertDatabaseHas('chats', [
        'instagram_sender_id' => 'CUST_IG_999',
        'is_read' => true,
    ]);

    // 4. Send manual agent message
    Http::fake([
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => 'CUST_IG_999',
            'message_id' => 'mid.manual_agent_send',
        ], 200),
    ]);

    $sendRes = $this->postJson('/api/user/chat/instagram/send', [
        'instagram_item_id' => $item->id,
        'recipient_id' => 'CUST_IG_999',
        'message' => 'Agent manual reply here',
    ]);

    $sendRes->assertCreated()
        ->assertJsonPath('status', true);

    $this->assertDatabaseHas('chats', [
        'instagram_item_id' => $item->id,
        'message' => 'Agent manual reply here',
        'sender_type' => 'agent',
    ]);

    // 5. Mark as read endpoint
    $markRes = $this->postJson('/api/user/chat/mark-as-read', [
        'channel' => 'instagram',
        'instagram_item_id' => $item->id,
        'sender_id' => 'CUST_IG_999',
    ]);

    $markRes->assertOk()
        ->assertJsonPath('status', true);
});

test('instagram webhook ignores echo when sent by bot account itself', function () {
    $item = InstagramItem::factory()->withQuota(100)->create([
        'instagram_id' => '17841400088888888',
        'status' => 'active',
    ]);

    $payload = [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => '17841400088888888',
                'messaging' => [
                    [
                        'sender' => ['id' => '17841400088888888'],
                        'recipient' => ['id' => 'CUST_IG_999'],
                        'message' => [
                            'mid' => 'mid.bot_outbound_echo',
                            'text' => 'Bot outbound reply',
                            'is_echo' => true,
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/instagram-webhook', $payload);
    $response->assertOk()
        ->assertJson(['status' => 'echo_ignored']);

    $item->refresh();
    expect($item->msg_number)->toBe(100);
});

test('instagram webhook resolves and replies to customer message from tester echo event', function () {
    Event::fake([InstagramEvent::class, TypingEvent::class]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->withQuota(50)->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841449192689340',
        'username' => 'keeto_app',
        'access_token' => 'VALID_PAGE_ACCESS_TOKEN',
        'status' => 'active',
    ]);

    // Construct mid containing the bot's instagram_id in base64
    $rawMidContent = 'ig_dm_item:1:IGMessageID:17841449192689340:thread_123:msg_456';
    $encodedMid = base64_encode($rawMidContent);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_ig_echo_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'مرحباً بك في كيتو! نحن في خدمتك.',
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    Http::fake([
        // 1. Meta Graph API query for message details by mid
        "https://graph.facebook.com/*/{$encodedMid}*" => Http::response([
            'id' => $encodedMid,
            'from' => [
                'id' => '28479796635013446',
                'username' => 'olaallaamm',
            ],
            'to' => [
                'data' => [
                    ['id' => '17841449192689340', 'username' => 'keeto_app'],
                ],
            ],
            'message' => 'Hello Keeto from tester!',
        ], 200),

        // 2. Meta Graph API mark seen and send message
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => '28479796635013446',
            'message_id' => 'mid.reply_success_123',
        ], 200),
    ]);

    // Webhook event sent by Meta when tester olaallaamm (17841428359357619) writes to keeto_app
    $payload = [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => '17841428359357619',
                'messaging' => [
                    [
                        'sender' => ['id' => '17841428359357619'],
                        'recipient' => ['id' => '1121098273691944'],
                        'message' => [
                            'mid' => $encodedMid,
                            'text' => 'Hello Keeto from tester!',
                            'is_echo' => true,
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/instagram-webhook', $payload);

    $response->assertOk()
        ->assertJson([
            'status' => 'success',
            'instagram_sent' => true,
        ]);

    // Check quota decrement
    $item->refresh();
    expect($item->msg_number)->toBe(49);

    // Verify chats saved with resolved sender ID and name
    $this->assertDatabaseHas('chats', [
        'user_id' => $restaurant->id,
        'instagram_item_id' => $item->id,
        'channel' => 'instagram',
        'instagram_sender_id' => '28479796635013446',
        'name' => 'olaallaamm',
        'message' => 'Hello Keeto from tester!',
        'is_admin' => false,
        'sender_type' => 'customer',
    ]);

    $this->assertDatabaseHas('chats', [
        'user_id' => $restaurant->id,
        'instagram_item_id' => $item->id,
        'channel' => 'instagram',
        'instagram_sender_id' => '28479796635013446',
        'is_admin' => true,
        'sender_type' => 'bot',
    ]);
});

test('instagram conversations cleans up ghost sender ids and lazily resolves real profile name from Meta', function () {
    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->withQuota(50)->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841449192689340',
        'page_id' => '1050529558136350',
        'access_token' => 'EAA_test_access_token_123',
    ]);

    // 1. Create a ghost chat that had been saved with the business page ID
    Chat::create([
        'user_id' => $restaurant->id,
        'instagram_item_id' => $item->id,
        'name' => 'Instagram User',
        'message' => 'Ghost echo message',
        'is_image' => false,
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
        'channel' => 'instagram',
        'instagram_sender_id' => '1121098273691944',
    ]);

    // 2. Create a real customer chat with generic 'Instagram User' name
    Chat::create([
        'user_id' => $restaurant->id,
        'instagram_item_id' => $item->id,
        'name' => 'Instagram User',
        'message' => 'Hello from Ola!',
        'is_image' => false,
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
        'channel' => 'instagram',
        'instagram_sender_id' => '28479796635013446',
    ]);

    // Mock Graph API call to fetch profile for 28479796635013446
    Http::fake([
        'https://graph.facebook.com/*/28479796635013446*' => Http::response([
            'id' => '28479796635013446',
            'name' => 'Ola Allaamm',
            'username' => 'olaallaamm',
        ], 200),
    ]);

    $response = $this->actingAs($restaurant)
        ->getJson("/api/user/chat/instagram/conversations?instagram_item_id={$item->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sender_id', '28479796635013446')
        ->assertJsonPath('data.0.name', 'Ola Allaamm');

    // Verify ghost chat was cleaned up from DB
    $this->assertDatabaseMissing('chats', [
        'instagram_sender_id' => '1121098273691944',
    ]);

    // Verify DB chat row name was updated to real name
    $this->assertDatabaseHas('chats', [
        'instagram_sender_id' => '28479796635013446',
        'name' => 'Ola Allaamm',
    ]);
});
