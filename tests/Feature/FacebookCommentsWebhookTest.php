<?php

use App\Events\MessengerEvent;
use App\Models\Chat;
use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

test('facebook comments webhook verifies GET challenge successfully', function () {
    $account = MessengerAccount::factory()->create([
        'verify_token' => 'comment-verify-token-123',
    ]);

    $response = $this->get(
        '/api/facebook-comments-webhook?hub_mode=subscribe&hub_verify_token=comment-verify-token-123&hub_challenge=CHALLENGE_COMMENT_OK'
    );

    $response->assertOk();
    expect($response->getContent())->toBe('CHALLENGE_COMMENT_OK');
});

test('facebook comments webhook ignores non-comment or non-add changes', function () {
    $response = $this->postJson('/api/facebook-comments-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => '106565280821724',
                'changes' => [
                    [
                        'field' => 'feed',
                        'value' => [
                            'item' => 'reaction',
                            'verb' => 'add',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'ignored_non_comment_add']);
});

test('facebook comments webhook ignores comments made by the page itself', function () {
    $pageId = '106565280821724';

    $response = $this->postJson('/api/facebook-comments-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $pageId,
                'changes' => [
                    [
                        'field' => 'feed',
                        'value' => [
                            'item' => 'comment',
                            'verb' => 'add',
                            'comment_id' => 'comment_999',
                            'post_id' => 'post_888',
                            'from' => [
                                'id' => $pageId, // Same as page ID
                                'name' => 'My Restaurant Page',
                            ],
                            'message' => 'تعليق من الصفحة نفسها',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'self_comment_ignored']);
});

test('facebook comments webhook handles already processed comment (idempotency)', function () {
    $commentId = 'comment_already_done';
    Cache::put("fb_comment_replied_{$commentId}", true, now()->addHour());

    $response = $this->postJson('/api/facebook-comments-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => '106565280821724',
                'changes' => [
                    [
                        'field' => 'feed',
                        'value' => [
                            'item' => 'comment',
                            'verb' => 'add',
                            'comment_id' => $commentId,
                            'post_id' => 'post_888',
                            'from' => [
                                'id' => 'user_123',
                                'name' => 'Ahmed',
                            ],
                            'message' => 'بكام العرض؟',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'already_processed']);
});

test('facebook comments webhook sends fallback when subscription or quota is exhausted (لو ai خلصان)', function () {
    Http::fake([
        'https://graph.facebook.com/*/comments' => Http::response(['id' => 'comment_reply_id_1'], 200),
        'https://graph.facebook.com/*/me/messages' => Http::response(['recipient_id' => 'user_123', 'message_id' => 'mid_1'], 200),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'page_id' => '106565280821724',
        'msg_number' => 0, // Out of quota
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/facebook-comments-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'changes' => [
                    [
                        'field' => 'feed',
                        'value' => [
                            'item' => 'comment',
                            'verb' => 'add',
                            'comment_id' => 'comment_exhausted_1',
                            'post_id' => 'post_100',
                            'from' => [
                                'id' => 'customer_psid_1',
                                'name' => 'محمود',
                            ],
                            'message' => 'ممكن تفاصيل أكتر؟',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson([
        'status' => 'fallback_sent',
        'reason' => 'quota_exhausted_or_expired',
        'comment_sent' => true,
        'messenger_sent' => true,
    ]);

    // msg_number remains 0 (not decremented below zero)
    expect($account->fresh()->msg_number)->toBe(0);
});

test('facebook comments webhook processes inquiry comment, decrements quota, and sends replies', function () {
    Event::fake([MessengerEvent::class]);

    Http::fake([
        'https://graph.facebook.com/*/post_200*' => Http::response([
            'message' => 'خصم 20% على وجبة العائلة اليوم فقط!',
        ], 200),
        'https://graph.facebook.com/*/comments' => Http::response([
            'id' => 'reply_comment_200',
        ], 200),
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => 'customer_psid_2',
            'message_id' => 'mid_reply_200',
        ], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_ai_comment_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode([
                                'is_inquiry' => true,
                                'public_comment_reply' => 'أهلاً بك يا أحمد! تم الرد على الخاص بالتفاصيل كاملة، يسعدنا تواصلك دائماً 😊',
                                'private_messenger_reply' => 'أهلاً وسهلاً بك يا أحمد 🌸 بخصوص عرض وجبة العائلة، سعرها بعد الخصم 200 جنيه ويشمل التوصيل المجاني!',
                            ], JSON_UNESCAPED_UNICODE),
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'page_id' => '106565280821724',
        'page_access_token' => 'EAAG_fake_token',
        'msg_number' => 10,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'status' => 'active',
        'ai_context' => 'مطعم مشويات يقدم أفضل اللحوم الطازجة.',
    ]);

    $response = $this->postJson('/api/facebook-comments-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'changes' => [
                    [
                        'field' => 'feed',
                        'value' => [
                            'item' => 'comment',
                            'verb' => 'add',
                            'comment_id' => 'comment_inquiry_200',
                            'post_id' => 'post_200',
                            'from' => [
                                'id' => 'customer_psid_2',
                                'name' => 'أحمد يحيى',
                            ],
                            'message' => 'بكام العرض ده والتوصيل متاح؟',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson([
        'status' => 'success',
        'is_inquiry' => true,
        'comment_sent' => true,
        'messenger_sent' => true,
    ]);

    // Check msg_number decremented from 10 to 9
    expect($account->fresh()->msg_number)->toBe(9);

    // Check MsgSend recorded
    expect(MsgSend::where('messenger_account_id', $account->id)->where('channel', 'messenger')->count())->toBe(1);

    // Check Chat recorded for customer
    $chat = Chat::where('messenger_account_id', $account->id)
        ->where('channel', 'messenger')
        ->latest('id')
        ->first();

    expect($chat)->not->toBeNull();
    expect($chat->message)->toContain('أهلاً وسهلاً بك يا أحمد');
    expect($chat->sender_type)->toBe('bot');
});

test('facebook comments webhook processes non-inquiry comment (appreciation) and only replies publicly', function () {
    Http::fake([
        'https://graph.facebook.com/*/comments' => Http::response([
            'id' => 'reply_comment_300',
        ], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_ai_comment_2',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode([
                                'is_inquiry' => false,
                                'public_comment_reply' => 'شكراً جزيلاً لذوقك يا سارة! نتشرف بخدمتك دائماً ❤️',
                                'private_messenger_reply' => null,
                            ], JSON_UNESCAPED_UNICODE),
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'page_id' => '106565280821724',
        'page_access_token' => 'EAAG_fake_token',
        'msg_number' => 5,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/facebook-comments-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'changes' => [
                    [
                        'field' => 'feed',
                        'value' => [
                            'item' => 'comment',
                            'verb' => 'add',
                            'comment_id' => 'comment_praise_300',
                            'post_id' => 'post_300',
                            'from' => [
                                'id' => 'customer_psid_3',
                                'name' => 'سارة علي',
                            ],
                            'message' => 'ما شاء الله الخدمة ممتازة جداً بالتوفيق',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson([
        'status' => 'success',
        'is_inquiry' => false,
        'comment_sent' => true,
        'messenger_sent' => false,
    ]);

    // Check msg_number decremented from 5 to 4
    expect($account->fresh()->msg_number)->toBe(4);

    // No private chat recorded because it wasn't an inquiry
    expect(Chat::where('messenger_account_id', $account->id)->count())->toBe(0);
});
