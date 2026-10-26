<?php

use App\Models\Chat;
use App\Models\MsgSend;
use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

test('user can connect a telegram bot with valid token', function () {
    $user = User::factory()->create(['role' => 'user']);

    Http::fake([
        'https://api.telegram.org/bot*/getMe' => Http::response([
            'ok' => true,
            'result' => [
                'id' => 987654321,
                'is_bot' => true,
                'first_name' => 'SmartEgo Bot',
                'username' => 'smartego_bot',
            ],
        ], 200),
        'https://api.telegram.org/bot*/setWebhook' => Http::response([
            'ok' => true,
            'result' => true,
            'description' => 'Webhook was set',
        ], 200),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/user/telegram/connect', [
            'bot_token' => '987654321:AAFakeTokenForTesting',
            'ai_context' => 'سياق مخصص للرد الآلي',
            'website_url' => 'https://smartego.org',
            'android_link' => 'https://play.google.com/test',
            'ios_link' => 'https://apple.com/test',
        ]);

    $response->assertCreated()
        ->assertJson([
            'status' => true,
            'data' => [
                'bot_id' => '987654321',
                'bot_username' => 'smartego_bot',
                'bot_name' => 'SmartEgo Bot',
                'ai_context' => 'سياق مخصص للرد الآلي',
                'website_url' => 'https://smartego.org',
            ],
        ]);

    expect(TelegramAccount::where('bot_id', '987654321')->exists())->toBeTrue();
});

test('user can update telegram account data', function () {
    Storage::fake('public');
    $user = User::factory()->create(['role' => 'user']);
    $account = TelegramAccount::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->create('menu.md', 100, 'text/markdown');

    $response = $this->actingAs($user)
        ->postJson("/api/user/telegram/accounts/{$account->id}", [
            'ai_context' => 'سياق معدل للذكاء الاصطناعي',
            'website_url' => 'https://updated.smartego.org',
            'android_link' => 'https://play.google.com/updated',
            'ios_link' => 'https://apple.com/updated',
            'ai_file' => $file,
        ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'id' => $account->id,
                'ai_context' => 'سياق معدل للذكاء الاصطناعي',
                'website_url' => 'https://updated.smartego.org',
            ],
        ]);

    $account->refresh();
    expect($account->ai_context)->toBe('سياق معدل للذكاء الاصطناعي')
        ->and($account->website_url)->toBe('https://updated.smartego.org')
        ->and($account->ai_file)->not->toBeNull();
});

test('admin can view and update telegram account data', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $account = TelegramAccount::factory()->create(['user_id' => $user->id, 'msg_number' => 50]);

    $response = $this->actingAs($admin)
        ->putJson("/api/admin/users/{$user->id}/telegram-accounts/{$account->id}", [
            'msg_number' => 500,
            'status' => 'active',
            'ai_context' => 'تعديل من لوحة الأدمن',
        ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'msg_number' => 500,
                'ai_context' => 'تعديل من لوحة الأدمن',
            ],
        ]);

    expect($account->fresh()->msg_number)->toBe(500);
});

test('telegram webhook handles incoming customer message and sends ai reply', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'msg_number' => 10,
        'status' => 'active',
        'verify_token' => 'test-secret-token-123',
    ]);

    Http::fake([
        'https://api.telegram.org/bot*/sendMessage' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 5555,
                'chat' => ['id' => 123456],
                'text' => 'أهلاً بك! نسعد بخدمتك.',
            ],
        ], 200),
    ]);

    // Mock OpenAI responses facade
    OpenAI::fake([
        CreateResponse::fake([
            'output_text' => 'أهلاً بك، تفضل كيف أساعدك؟',
            'usage' => [
                'total_tokens' => 45,
            ],
        ]),
    ]);

    $payload = [
        'update_id' => 10001,
        'message' => [
            'message_id' => 9999,
            'from' => [
                'id' => 123456,
                'is_bot' => false,
                'first_name' => 'Ahmed',
                'last_name' => 'Ali',
                'username' => 'ahmed_ali',
            ],
            'chat' => [
                'id' => 123456,
                'type' => 'private',
            ],
            'text' => 'السلام عليكم، عاوز أعرف الأسعار',
        ],
    ];

    $response = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret-token-123')
        ->postJson("/api/telegram-webhook/{$account->id}", $payload);

    $response->assertOk()
        ->assertJson(['status' => 'ok', 'reply_sent' => true]);

    // Verify incoming customer message in Chat
    expect(Chat::where('telegram_account_id', $account->id)
        ->where('sender_type', 'customer')
        ->where('message', 'السلام عليكم، عاوز أعرف الأسعار')
        ->exists())->toBeTrue();

    // Verify outgoing bot message in Chat
    expect(Chat::where('telegram_account_id', $account->id)
        ->where('sender_type', 'bot')
        ->exists())->toBeTrue();

    // Verify quota decremented
    expect($account->fresh()->msg_number)->toBe(9);

    // Verify MsgSend recorded
    expect(MsgSend::where('telegram_account_id', $account->id)->exists())->toBeTrue();
});

test('user can view telegram chat conversations, messages, and send manual reply', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'msg_number' => 20,
        'status' => 'active',
    ]);

    // Create a customer chat
    Chat::create([
        'user_id' => $user->id,
        'telegram_account_id' => $account->id,
        'telegram_sender_id' => '123456',
        'channel' => 'telegram',
        'name' => 'Ahmed Ali',
        'message' => 'مرحباً، هل يوجد توصيل؟',
        'is_image' => false,
        'is_admin' => false,
        'is_read' => false,
        'sender_type' => 'customer',
    ]);

    // 1. List accounts
    $accountsRes = $this->actingAs($user)->getJson('/api/user/chat/telegram/accounts');
    $accountsRes->assertOk()
        ->assertJsonStructure(['status', 'data']);

    // 2. List conversations
    $convRes = $this->actingAs($user)->getJson("/api/user/chat/telegram/conversations?telegram_account_id={$account->id}");
    $convRes->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                [
                    'sender_id' => '123456',
                    'name' => 'Ahmed Ali',
                    'last_message' => 'مرحباً، هل يوجد توصيل؟',
                    'unread_count' => 1,
                ],
            ],
        ]);

    // 3. View messages (should auto-mark as read)
    $msgsRes = $this->actingAs($user)->getJson("/api/user/chat/telegram/messages?telegram_account_id={$account->id}&sender_id=123456");
    $msgsRes->assertOk()
        ->assertJsonStructure(['status', 'data']);

    expect(Chat::where('telegram_sender_id', '123456')->where('is_read', true)->exists())->toBeTrue();

    // 4. Send manual reply
    Http::fake([
        'https://api.telegram.org/bot*/sendMessage' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 7777,
                'chat' => ['id' => 123456],
                'text' => 'نعم متوفر توصيل لجميع المناطق.',
            ],
        ], 200),
    ]);

    $replyRes = $this->actingAs($user)->postJson('/api/user/chat/telegram/send', [
        'telegram_account_id' => $account->id,
        'recipient_id' => '123456',
        'message' => 'نعم متوفر توصيل لجميع المناطق.',
    ]);

    $replyRes->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'sender_id' => '123456',
                'message' => 'نعم متوفر توصيل لجميع المناطق.',
            ],
        ]);

    expect(Chat::where('telegram_sender_id', '123456')
        ->where('is_admin', true)
        ->where('sender_type', 'agent')
        ->exists())->toBeTrue();
});
