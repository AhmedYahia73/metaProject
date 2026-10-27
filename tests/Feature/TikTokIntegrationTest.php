<?php

use App\Models\Chat;
use App\Models\MsgSend;
use App\Models\TikTokAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

test('user can connect a tiktok account with valid data', function () {
    $user = User::factory()->create(['role' => 'user']);

    Http::fake([
        'https://open.tiktokapis.com/v2/user/info/*' => Http::response([
            'data' => [
                'user' => [
                    'open_id' => 'act_test_123456',
                    'username' => 'smartego_tiktok',
                    'display_name' => 'SmartEgo TikTok Store',
                    'avatar_url' => 'https://tiktok.com/avatar.jpg',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/user/tiktok/connect', [
            'access_token' => 'act.fake_access_token_123',
            'refresh_token' => 'rft.fake_refresh_token_123',
            'open_id' => 'act_test_123456',
            'ai_context' => 'سياق مخصص للرد على تيك توك',
            'website_url' => 'https://smartego.org',
            'android_link' => 'https://play.google.com/test',
            'ios_link' => 'https://apple.com/test',
        ]);

    $response->assertCreated()
        ->assertJson([
            'status' => true,
            'data' => [
                'open_id' => 'act_test_123456',
                'username' => 'smartego_tiktok',
                'display_name' => 'SmartEgo TikTok Store',
                'ai_context' => 'سياق مخصص للرد على تيك توك',
                'website_url' => 'https://smartego.org',
            ],
        ]);

    expect(TikTokAccount::where('open_id', 'act_test_123456')->exists())->toBeTrue();
});

test('user can update tiktok account data', function () {
    Storage::fake('public');
    $user = User::factory()->create(['role' => 'user']);
    $account = TikTokAccount::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->create('menu.md', 100, 'text/markdown');

    $response = $this->actingAs($user)
        ->postJson("/api/user/tiktok/accounts/{$account->id}", [
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

test('admin can view and update tiktok account data', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $account = TikTokAccount::factory()->create([
        'user_id' => $user->id,
        'msg_number' => 10,
        'status' => 'active',
    ]);

    // Admin index
    $indexResponse = $this->actingAs($admin)
        ->getJson("/api/admin/users/{$user->id}/tiktok-accounts");

    $indexResponse->assertOk()
        ->assertJson([
            'status' => true,
        ]);

    // Admin update
    $updateResponse = $this->actingAs($admin)
        ->putJson("/api/admin/users/{$user->id}/tiktok-accounts/{$account->id}", [
            'msg_number' => 500,
            'status' => 'disabled',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(60)->toDateString(),
        ]);

    $updateResponse->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'msg_number' => 500,
                'status' => 'disabled',
            ],
        ]);

    $account->refresh();
    expect($account->msg_number)->toBe(500)
        ->and($account->status)->toBe('disabled');
});

test('tiktok webhook handles incoming video comment and replies with ai', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = TikTokAccount::factory()->create([
        'user_id' => $user->id,
        'open_id' => 'business_tiktok_777',
        'status' => 'active',
        'msg_number' => 10,
        'ai_context' => 'أنت موظف خدمة عملاء في مطعم سمارت إيجو',
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output_text' => 'أهلاً بك! يمكنك الاطلاع على قائمتنا عبر الرابط التالي.',
            'usage' => [
                'total_tokens' => 45,
            ],
        ]),
    ]);

    Http::fake([
        'https://open.tiktokapis.com/v2/comment/reply/' => Http::response([
            'error' => ['code' => 'ok', 'message' => ''],
            'data' => ['reply_id' => 'rep_12345'],
        ], 200),
    ]);

    $payload = [
        'event' => 'comment.create',
        'data' => [
            'comment_id' => 'comment_9999',
            'business_id' => 'business_tiktok_777',
            'commenter_id' => 'customer_tiktok_111',
            'text' => 'ممكن تفاصيل المنيو والأسعار؟',
        ],
    ];

    $response = $this->postJson('/api/tiktok-webhook', $payload);

    $response->assertOk()
        ->assertJson([
            'status' => 'comment_replied',
        ]);

    expect(MsgSend::where('tiktok_account_id', $account->id)->where('channel', 'tiktok')->count())->toBe(1);

    $account->refresh();
    expect($account->msg_number)->toBe(9);
});

test('tiktok webhook handles incoming direct message and replies with ai', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = TikTokAccount::factory()->create([
        'user_id' => $user->id,
        'open_id' => 'business_tiktok_888',
        'display_name' => 'SmartEgo Store',
        'status' => 'active',
        'msg_number' => 20,
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output_text' => 'أهلاً بك يا فندم! كيف يمكننا مساعدتك اليوم؟',
            'usage' => [
                'total_tokens' => 35,
            ],
        ]),
    ]);

    Http::fake([
        'https://open.tiktokapis.com/v2/im/message/send/' => Http::response([
            'error' => ['code' => 'ok', 'message' => ''],
            'data' => ['message_id' => 'msg_out_555'],
        ], 200),
    ]);

    $payload = [
        'event' => 'im.message.receive',
        'data' => [
            'message' => [
                'message_id' => 'msg_in_12345',
                'sender_id' => 'customer_sender_101',
                'recipient_id' => 'business_tiktok_888',
                'text' => 'السلام عليكم، عايز أطلب أوردر',
                'sender_name' => 'أحمد علي',
            ],
        ],
    ];

    $response = $this->postJson('/api/tiktok-webhook', $payload);

    $response->assertOk()
        ->assertJson([
            'status' => 'message_replied',
        ]);

    // Check stored messages in chat table
    $customerChat = Chat::where('tiktok_account_id', $account->id)
        ->where('sender_type', 'customer')
        ->first();

    expect($customerChat)->not->toBeNull()
        ->and($customerChat->message)->toBe('السلام عليكم، عايز أطلب أوردر')
        ->and($customerChat->name)->toBe('أحمد علي');

    $botChat = Chat::where('tiktok_account_id', $account->id)
        ->where('sender_type', 'bot')
        ->first();

    expect($botChat)->not->toBeNull()
        ->and($botChat->meta_message_id)->toBe('msg_out_555');

    $account->refresh();
    expect($account->msg_number)->toBe(19);
});

test('user can view tiktok chat conversations, messages, and send manual reply', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = TikTokAccount::factory()->create([
        'user_id' => $user->id,
        'open_id' => 'business_tiktok_999',
        'status' => 'active',
        'msg_number' => 50,
    ]);

    // Create a customer chat and a bot chat
    Chat::create([
        'user_id' => $user->id,
        'tiktok_account_id' => $account->id,
        'tiktok_sender_id' => 'customer_tiktok_202',
        'name' => 'سارة محمد',
        'channel' => 'tiktok',
        'message' => 'عايزة أعرف مواعيد العمل',
        'is_image' => false,
        'is_admin' => false,
        'is_read' => false,
        'sender_type' => 'customer',
    ]);

    // 1. List accounts for chat
    $accountsResponse = $this->actingAs($user)
        ->getJson('/api/user/chat/tiktok/accounts');

    $accountsResponse->assertOk()
        ->assertJson([
            'status' => true,
        ]);

    // 2. List conversations
    $conversationsResponse = $this->actingAs($user)
        ->getJson("/api/user/chat/tiktok/conversations?tiktok_account_id={$account->id}");

    $conversationsResponse->assertOk()
        ->assertJson([
            'status' => true,
            'tiktok_account_id' => $account->id,
        ]);

    // 3. List messages
    $messagesResponse = $this->actingAs($user)
        ->getJson("/api/user/chat/tiktok/messages?tiktok_account_id={$account->id}&sender_id=customer_tiktok_202");

    $messagesResponse->assertOk()
        ->assertJson([
            'status' => true,
            'tiktok_account_id' => $account->id,
            'sender_id' => 'customer_tiktok_202',
        ]);

    // 4. Send manual reply as human agent
    Http::fake([
        'https://open.tiktokapis.com/v2/im/message/send/' => Http::response([
            'error' => ['code' => 'ok', 'message' => ''],
            'data' => ['message_id' => 'manual_reply_msg_777'],
        ], 200),
    ]);

    $sendResponse = $this->actingAs($user)
        ->postJson('/api/user/chat/tiktok/send', [
            'tiktok_account_id' => $account->id,
            'recipient_id' => 'customer_tiktok_202',
            'message' => 'مواعيد العمل يومياً من 10 صباحاً حتى 12 مساءً.',
        ]);

    $sendResponse->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'message' => 'مواعيد العمل يومياً من 10 صباحاً حتى 12 مساءً.',
                'sender_type' => 'agent',
            ],
        ]);

    expect(Chat::where('tiktok_account_id', $account->id)->where('sender_type', 'agent')->exists())->toBeTrue();
});
