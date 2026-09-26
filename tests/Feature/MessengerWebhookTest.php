<?php

use App\Models\Chat;
use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Verification (GET)
// ─────────────────────────────────────────────────────────────────────────────

test('messenger webhook verifies correctly with a known verify_token', function () {
    $account = MessengerAccount::factory()->create([
        'verify_token' => 'test-uuid-token-1234',
    ]);

    $response = $this->get(
        '/api/messenger-webhook?hub_mode=subscribe&hub_verify_token=test-uuid-token-1234&hub_challenge=CHALLENGE_999'
    );

    $response->assertOk();
    expect($response->getContent())->toBe('CHALLENGE_999');
});

test('messenger webhook verification fails with unknown verify_token', function () {
    $response = $this->get(
        '/api/messenger-webhook?hub_mode=subscribe&hub_verify_token=wrong-token&hub_challenge=CHALLENGE_999'
    );

    $response->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// POST — Incoming Messages
// ─────────────────────────────────────────────────────────────────────────────

test('messenger webhook ignores non-page events', function () {
    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'instagram',
        'entry' => [],
    ]);

    $response->assertOk()->assertJson(['status' => 'ignored_non_page']);
});

test('messenger webhook returns page_not_found for unknown page_id', function () {
    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [['id' => 'unknown_page_id', 'messaging' => []]],
    ]);

    $response->assertOk()->assertJson(['status' => 'page_not_found']);
});

test('messenger webhook returns page_not_found for disabled page', function () {
    $account = MessengerAccount::factory()->disabled()->create();

    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [['id' => $account->page_id, 'messaging' => []]],
    ]);

    $response->assertOk()->assertJson(['status' => 'page_not_found']);
});

test('messenger webhook returns limit_exceeded when restaurant has no active messenger subscription', function () {
    $restaurant = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create(['user_id' => $restaurant->id]);

    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'messaging' => [
                    [
                        'sender' => ['id' => 'PSID_123'],
                        'recipient' => ['id' => $account->page_id],
                        'message' => ['mid' => 'mid.test', 'text' => 'مرحبا'],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'limit_exceeded']);
});

test('messenger webhook ignores echo messages', function () {
    $restaurant = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create(['user_id' => $restaurant->id]);

    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'messaging' => [
                    [
                        'sender' => ['id' => $account->page_id],
                        'recipient' => ['id' => 'PSID_123'],
                        'message' => ['mid' => 'mid.echo', 'text' => 'replied', 'is_echo' => true],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'echo_ignored']);
});

test('messenger webhook processes message, gets AI reply, and sends messenger response', function () {
    Http::fake([
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => 'PSID_456',
            'message_id' => 'mid.reply_test_123',
        ], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_messenger_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'أهلاً بك! كيف أقدر أساعدك اليوم؟',
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create([
        'role' => 'user',
    ]);

    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'page_access_token' => 'EAAG_fake_page_token',
        'android_link' => 'https://play.google.com/test',
        'ios_link' => 'https://apps.apple.com/test',
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة ماسنجر', 'en' => 'Messenger Package'],
        'msg_number' => 200,
        'price' => 50,
        'months' => 1,
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 50,
        'final_price' => 50,
        'from' => now()->subDay()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
        'msgs' => 200,
    ]);

    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'messaging' => [
                    [
                        'sender' => ['id' => 'PSID_456'],
                        'recipient' => ['id' => $account->page_id],
                        'timestamp' => now()->timestamp,
                        'message' => ['mid' => 'mid.test_incoming', 'text' => 'عاوز أطلب بيتزا'],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'success', 'messenger_sent' => true]);

    // Customer message saved with correct channel
    $customerChat = Chat::where('user_id', $restaurant->id)
        ->where('messenger_sender_id', 'PSID_456')
        ->where('is_admin', false)
        ->where('channel', 'messenger')
        ->first();

    expect($customerChat)->not->toBeNull();
    expect($customerChat->message)->toBe('عاوز أطلب بيتزا');

    // AI reply saved
    $replyChat = Chat::where('user_id', $restaurant->id)
        ->where('messenger_sender_id', 'PSID_456')
        ->where('is_admin', true)
        ->where('channel', 'messenger')
        ->first();

    expect($replyChat)->not->toBeNull();
    expect($replyChat->message)->toContain('أهلاً بك! كيف أقدر أساعدك اليوم؟');

    // Messenger MsgSend recorded with correct channel
    expect(
        MsgSend::where('user_id', $restaurant->id)->where('channel', 'messenger')->count()
    )->toBe(1);

    // Messenger API called correctly
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'me/messages')
            && $request['recipient']['id'] === 'PSID_456'
            && $request['messaging_type'] === 'RESPONSE';
    });
});

test('messenger and whatsapp limits are counted independently', function () {
    $restaurant = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create(['user_id' => $restaurant->id]);

    $package = Package::create([
        'name' => ['ar' => 'باقة صغيرة', 'en' => 'Small'],
        'msg_number' => 1,
        'price' => 10,
        'months' => 1,
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 10,
        'final_price' => 10,
        'from' => now()->subDay()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
        'msgs' => 1,
    ]);

    // Add 1 WhatsApp message — should NOT affect Messenger limit
    MsgSend::create(['user_id' => $restaurant->id, 'channel' => 'whatsapp']);

    // Messenger still has 1 remaining — limit NOT exceeded
    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'messaging' => [
                    [
                        'sender' => ['id' => 'PSID_789'],
                        'recipient' => ['id' => $account->page_id],
                        'message' => ['mid' => 'mid.x', 'text' => 'مرحبا'],
                    ],
                ],
            ],
        ],
    ]);

    // Should NOT return limit_exceeded (AI will be called, may return ai_failed without mock, but not limit)
    $response->assertOk();
    expect($response->json('status'))->not->toBe('limit_exceeded');
});
