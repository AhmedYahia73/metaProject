<?php

use App\Models\Chat;
use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function createChatUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Messenger Chat Tests
// ─────────────────────────────────────────────────────────────────────────────

test('user can list their messenger pages with unread count, pagination and search', function () {
    $user = createChatUser();

    $account1 = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_name' => 'Burger House Cairo',
        'page_id' => '11111111',
        'status' => 'active',
    ]);

    $account2 = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_name' => 'Pizza Town Alex',
        'page_id' => '22222222',
        'status' => 'active',
    ]);

    // Add unread customer message to account1
    Chat::create([
        'user_id' => $user->id,
        'messenger_account_id' => $account1->id,
        'channel' => 'messenger',
        'messenger_sender_id' => 'psid_customer_1',
        'name' => 'Ahmed Customer',
        'message' => 'Hello Burger House',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
    ]);

    // Add read message to account1
    Chat::create([
        'user_id' => $user->id,
        'messenger_account_id' => $account1->id,
        'channel' => 'messenger',
        'messenger_sender_id' => 'psid_customer_1',
        'name' => 'Ahmed Customer',
        'message' => 'Thanks for replying',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => true,
        'read_at' => now(),
    ]);

    // 1. List all with pagination
    $response = $this->actingAs($user)
        ->getJson('/api/user/chat/messenger/pages?per_page=10');

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['id', 'page_id', 'page_name', 'status', 'unread_count', 'total_conversations'],
            ],
            'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    $data = collect($response->json('data'));
    $page1Data = $data->firstWhere('page_id', '11111111');
    expect($page1Data['unread_count'])->toBe(1);
    expect($page1Data['total_conversations'])->toBe(1);

    // 2. Search by page name
    $searchResponse = $this->actingAs($user)
        ->getJson('/api/user/chat/messenger/pages?search=Pizza');

    $searchResponse->assertOk()
        ->assertJsonCount(1, 'data');
    expect($searchResponse->json('data.0.page_name'))->toBe('Pizza Town Alex');
});

test('user can list conversations for a messenger page with search and pagination', function () {
    $user = createChatUser();

    $account = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_test_100',
    ]);

    // Conversation 1: Mahmoud
    Chat::create([
        'user_id' => $user->id,
        'messenger_account_id' => $account->id,
        'channel' => 'messenger',
        'messenger_sender_id' => 'psid_mahmoud',
        'name' => 'Mahmoud Ali',
        'message' => 'Do you have delivery to Maadi?',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
        'created_at' => now()->subMinutes(10),
    ]);

    // Conversation 2: Sara
    Chat::create([
        'user_id' => $user->id,
        'messenger_account_id' => $account->id,
        'channel' => 'messenger',
        'messenger_sender_id' => 'psid_sara',
        'name' => 'Sara Samir',
        'message' => 'What are your working hours?',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => true,
        'created_at' => now()->subMinutes(5),
    ]);

    // 1. List conversations with pagination
    $response = $this->actingAs($user)
        ->getJson("/api/user/chat/messenger/conversations?page_id={$account->page_id}&per_page=10");

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'page_id',
            'page_name',
            'data' => [
                '*' => ['sender_id', 'name', 'last_message', 'last_message_at', 'unread_count'],
            ],
            'pagination',
        ]);

    expect($response->json('data'))->toHaveCount(2);

    // 2. Search by customer name
    $searchResponse = $this->actingAs($user)
        ->getJson("/api/user/chat/messenger/conversations?page_id={$account->page_id}&search=Mahmoud");

    $searchResponse->assertOk()
        ->assertJsonCount(1, 'data');
    expect($searchResponse->json('data.0.sender_id'))->toBe('psid_mahmoud');
    expect($searchResponse->json('data.0.unread_count'))->toBe(1);
});

test('user viewing messenger messages auto-marks unread customer messages as read', function () {
    $user = createChatUser();

    $account = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_test_200',
    ]);

    $unreadChat = Chat::create([
        'user_id' => $user->id,
        'messenger_account_id' => $account->id,
        'channel' => 'messenger',
        'messenger_sender_id' => 'psid_customer_99',
        'name' => 'Omar Hany',
        'message' => 'I would like to order two burgers',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
    ]);

    expect($unreadChat->is_read)->toBeFalse();

    // View messages thread
    $response = $this->actingAs($user)
        ->getJson("/api/user/chat/messenger/messages?page_id={$account->page_id}&sender_id=psid_customer_99");

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'page_id',
            'sender_id',
            'data' => [
                '*' => ['id', 'message', 'is_admin', 'sender_type', 'is_read', 'read_at', 'created_at'],
            ],
            'pagination',
        ]);

    // Check DB that is_read was updated to true
    $unreadChat->refresh();
    expect($unreadChat->is_read)->toBeTrue();
    expect($unreadChat->read_at)->not->toBeNull();
});

test('user can send manual message to messenger customer and decrements msg_number', function () {
    $user = createChatUser();

    $account = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_test_300',
        'page_access_token' => 'EAA_FAKETOKEN',
        'status' => 'active',
        'msg_number' => 10,
    ]);

    Http::fake([
        'https://graph.facebook.com/v21.0/me/messages' => Http::response([
            'recipient_id' => 'psid_customer_77',
            'message_id' => 'mid.1234567890:abcdef',
        ], 200),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/user/chat/messenger/send', [
            'page_id' => $account->page_id,
            'recipient_id' => 'psid_customer_77',
            'message' => 'Hello! Your order has been confirmed.',
        ]);

    $response->assertCreated()
        ->assertJson([
            'status' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'message' => 'Hello! Your order has been confirmed.',
                'sender_type' => 'agent',
                'is_read' => true,
            ],
        ]);

    // Account msg_number decremented
    $account->refresh();
    expect($account->msg_number)->toBe(9);

    // Chat created with sender_type = agent
    $this->assertDatabaseHas('chats', [
        'user_id' => $user->id,
        'messenger_account_id' => $account->id,
        'channel' => 'messenger',
        'messenger_sender_id' => 'psid_customer_77',
        'sender_type' => 'agent',
        'is_admin' => true,
        'is_read' => true,
        'message' => 'Hello! Your order has been confirmed.',
        'meta_message_id' => 'mid.1234567890:abcdef',
    ]);

    // MsgSend tracked
    $this->assertDatabaseHas('msg_sends', [
        'user_id' => $user->id,
        'channel' => 'messenger',
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// WhatsApp Chat Tests
// ─────────────────────────────────────────────────────────────────────────────

test('user can list their whats numbers with unread count, pagination and search', function () {
    $user = createChatUser();

    $item1 = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01011112222',
        'phone_number_id' => 'pid_111',
        'phone_status' => 'active',
    ]);

    $item2 = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01033334444',
        'phone_number_id' => 'pid_222',
        'phone_status' => 'active',
    ]);

    // Add unread customer message to item1
    Chat::create([
        'user_id' => $user->id,
        'whats_item_id' => $item1->id,
        'channel' => 'whatsapp',
        'phone' => '201099998888',
        'name' => 'Kareem Customer',
        'message' => 'Hello on WhatsApp',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
    ]);

    // 1. List with pagination
    $response = $this->actingAs($user)
        ->getJson('/api/user/chat/whatsapp/numbers?per_page=10');

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['id', 'phone', 'phone_number_id', 'phone_status', 'unread_count', 'total_conversations'],
            ],
            'pagination',
        ]);

    $data = collect($response->json('data'));
    $item1Data = $data->firstWhere('id', $item1->id);
    expect($item1Data['unread_count'])->toBe(1);
    expect($item1Data['total_conversations'])->toBe(1);

    // 2. Search by phone
    $searchResponse = $this->actingAs($user)
        ->getJson('/api/user/chat/whatsapp/numbers?search=3333');

    $searchResponse->assertOk()
        ->assertJsonCount(1, 'data');
    expect($searchResponse->json('data.0.phone'))->toBe('01033334444');
});

test('user can list conversations for a whats number with search and pagination', function () {
    $user = createChatUser();

    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01055556666',
        'phone_status' => 'active',
    ]);

    // Conversation 1: Tamer
    Chat::create([
        'user_id' => $user->id,
        'whats_item_id' => $item->id,
        'channel' => 'whatsapp',
        'phone' => '201011112222',
        'name' => 'Tamer Hosny',
        'message' => 'Can I reserve a table for 4?',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
        'created_at' => now()->subMinutes(15),
    ]);

    // Conversation 2: Mona
    Chat::create([
        'user_id' => $user->id,
        'whats_item_id' => $item->id,
        'channel' => 'whatsapp',
        'phone' => '201033334444',
        'name' => 'Mona Zaki',
        'message' => 'Send me the dessert menu',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => true,
        'created_at' => now()->subMinutes(5),
    ]);

    // 1. List conversations with pagination
    $response = $this->actingAs($user)
        ->getJson("/api/user/chat/whatsapp/conversations?whats_item_id={$item->id}&per_page=10");

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'whats_item_id',
            'phone',
            'data' => [
                '*' => ['phone', 'name', 'last_message', 'last_message_at', 'unread_count'],
            ],
            'pagination',
        ]);

    expect($response->json('data'))->toHaveCount(2);

    // 2. Search by phone or name
    $searchResponse = $this->actingAs($user)
        ->getJson("/api/user/chat/whatsapp/conversations?whats_item_id={$item->id}&search=Tamer");

    $searchResponse->assertOk()
        ->assertJsonCount(1, 'data');
    expect($searchResponse->json('data.0.name'))->toBe('Tamer Hosny');
    expect($searchResponse->json('data.0.unread_count'))->toBe(1);
});

test('user viewing whats messages auto-marks unread customer messages as read', function () {
    $user = createChatUser();

    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01077778888',
        'phone_status' => 'active',
    ]);

    $unreadChat = Chat::create([
        'user_id' => $user->id,
        'whats_item_id' => $item->id,
        'channel' => 'whatsapp',
        'phone' => '201088887777',
        'name' => 'Amr Diab',
        'message' => 'Is there live music tonight?',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
    ]);

    expect($unreadChat->is_read)->toBeFalse();

    // View messages thread
    $response = $this->actingAs($user)
        ->getJson("/api/user/chat/whatsapp/messages?whats_item_id={$item->id}&phone=201088887777");

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'whats_item_id',
            'customer_phone',
            'data' => [
                '*' => ['id', 'message', 'is_admin', 'sender_type', 'is_read', 'read_at', 'created_at'],
            ],
            'pagination',
        ]);

    $unreadChat->refresh();
    expect($unreadChat->is_read)->toBeTrue();
    expect($unreadChat->read_at)->not->toBeNull();
});

test('user can send manual message to whats customer and decrements msg_number', function () {
    $user = createChatUser();

    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01012345678',
        'phone_number_id' => '10987654321',
        'access_token' => 'WHATS_ACCESS_TOKEN',
        'phone_status' => 'active',
        'msg_number' => 20,
    ]);

    Http::fake([
        'https://graph.facebook.com/v21.0/10987654321/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '201098765432', 'wa_id' => '201098765432']],
            'messages' => [['id' => 'wamid.HBgLMjAxMDk4NzY1NDMyFQIAERgSR...']],
        ], 200),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/user/chat/whatsapp/send', [
            'whats_item_id' => $item->id,
            'phone' => '01098765432',
            'message' => 'Your reservation is confirmed for 8 PM.',
        ]);

    $response->assertCreated()
        ->assertJson([
            'status' => true,
            'message' => 'WhatsApp message sent successfully.',
            'data' => [
                'message' => 'Your reservation is confirmed for 8 PM.',
                'sender_type' => 'agent',
                'is_read' => true,
            ],
        ]);

    // Item msg_number decremented
    $item->refresh();
    expect($item->msg_number)->toBe(19);

    // Chat created with sender_type = agent
    $this->assertDatabaseHas('chats', [
        'user_id' => $user->id,
        'whats_item_id' => $item->id,
        'channel' => 'whatsapp',
        'phone' => '201098765432',
        'sender_type' => 'agent',
        'is_admin' => true,
        'is_read' => true,
        'message' => 'Your reservation is confirmed for 8 PM.',
    ]);

    // MsgSend tracked
    $this->assertDatabaseHas('msg_sends', [
        'user_id' => $user->id,
        'channel' => 'whatsapp',
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Explicit Mark As Read Test
// ─────────────────────────────────────────────────────────────────────────────

test('user can explicitly mark conversation as read via mark-as-read endpoint', function () {
    $user = createChatUser();

    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone_status' => 'active',
    ]);

    Chat::create([
        'user_id' => $user->id,
        'whats_item_id' => $item->id,
        'channel' => 'whatsapp',
        'phone' => '201111222333',
        'message' => 'Message 1',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
    ]);

    Chat::create([
        'user_id' => $user->id,
        'whats_item_id' => $item->id,
        'channel' => 'whatsapp',
        'phone' => '201111222333',
        'message' => 'Message 2',
        'is_admin' => false,
        'sender_type' => 'customer',
        'is_read' => false,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/user/chat/mark-as-read', [
            'channel' => 'whatsapp',
            'whats_item_id' => $item->id,
            'phone' => '201111222333',
        ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'marked_count' => 2,
        ]);

    $unreadRemaining = Chat::where('user_id', $user->id)
        ->where('whats_item_id', $item->id)
        ->where('is_read', false)
        ->count();

    expect($unreadRemaining)->toBe(0);
});
