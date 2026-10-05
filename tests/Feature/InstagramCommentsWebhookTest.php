<?php

use App\Events\InstagramEvent;
use App\Models\Chat;
use App\Models\InstagramItem;
use App\Models\MsgSend;
use App\Models\User;
use App\Services\MetaPageTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

test('instagram comments webhook verifies GET challenge successfully', function () {
    $item = InstagramItem::factory()->create([
        'verify_token' => 'ig-comment-verify-token-123',
    ]);

    $response = $this->get(
        '/api/instagram-comments-webhook?hub_mode=subscribe&hub_verify_token=ig-comment-verify-token-123&hub_challenge=CHALLENGE_IG_COMMENT_OK'
    );

    $response->assertOk();
    expect($response->getContent())->toBe('CHALLENGE_IG_COMMENT_OK');
});

test('instagram comments webhook ignores non-comment changes', function () {
    $response = $this->postJson('/api/instagram-comments-webhook', [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => '17841400000000000',
                'changes' => [
                    [
                        'field' => 'story_insights',
                        'value' => [
                            'item' => 'reaction',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'ignored_non_comments']);
});

test('instagram comments webhook ignores comments made by the account itself', function () {
    $igAccountId = '17841400000000000';
    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => $igAccountId,
        'username' => 'my_restaurant_ig',
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/instagram-comments-webhook', [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => $igAccountId,
                'changes' => [
                    [
                        'field' => 'comments',
                        'value' => [
                            'id' => 'comment_ig_self_1',
                            'media' => ['id' => 'media_100'],
                            'from' => [
                                'id' => $igAccountId,
                                'username' => 'my_restaurant_ig',
                            ],
                            'text' => 'تعليق من الحساب نفسه',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'self_comment_ignored']);
});

test('instagram comments webhook handles already processed comment (idempotency)', function () {
    $commentId = 'ig_comment_already_done';
    Cache::put("ig_comment_replied_{$commentId}", true, now()->addHour());

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841400000000000',
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/instagram-comments-webhook', [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => $item->instagram_id,
                'changes' => [
                    [
                        'field' => 'comments',
                        'value' => [
                            'id' => $commentId,
                            'media' => ['id' => 'media_100'],
                            'from' => [
                                'id' => 'customer_ig_1',
                                'username' => 'ahmed_customer',
                            ],
                            'text' => 'بكام العرض؟',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'already_processed']);
});

test('instagram comments webhook sends fallback when subscription or quota is exhausted (لو ai خلصان)', function () {
    Event::fake([InstagramEvent::class]);

    Http::fake([
        'https://graph.facebook.com/*/replies' => Http::response(['id' => 'ig_comment_reply_id_1'], 200),
        'https://graph.facebook.com/*/me/messages' => Http::response(['recipient_id' => 'customer_ig_1', 'message_id' => 'mid_ig_1'], 200),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841400000000000',
        'msg_number' => 0, // Out of quota
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/instagram-comments-webhook', [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => $item->instagram_id,
                'changes' => [
                    [
                        'field' => 'comments',
                        'value' => [
                            'id' => 'comment_exhausted_ig_1',
                            'media' => ['id' => 'media_100'],
                            'from' => [
                                'id' => 'customer_ig_1',
                                'username' => 'mahmoud_ig',
                            ],
                            'text' => 'ممكن تفاصيل أكتر؟',
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
        'instagram_sent' => true,
    ]);

    // Check Chat created in database for Instagram
    $chat = Chat::where('instagram_item_id', $item->id)->latest('id')->first();
    expect($chat)->not->toBeNull();
    expect($chat->channel)->toBe('instagram');
    expect($chat->message)->toContain('شكراً لاهتمامك وتواصلك معنا');
    expect($chat->instagram_sender_id)->toBe('customer_ig_1');

    Event::assertDispatched(InstagramEvent::class);

    // msg_number remains 0 (not decremented below zero)
    expect($item->fresh()->msg_number)->toBe(0);
});

test('instagram comments webhook processes inquiry comment, decrements quota, and opens chat', function () {
    Event::fake([InstagramEvent::class]);

    Http::fake([
        'https://graph.facebook.com/*/media_ig_200*' => Http::response([
            'caption' => 'عرض خاص على وجبة العائلة بخصم 20% لفترة محدودة!',
        ], 200),
        'https://graph.facebook.com/*/replies' => Http::response([
            'id' => 'ig_reply_comment_200',
        ], 200),
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => 'customer_igsid_2',
            'message_id' => 'mid_ig_reply_200',
        ], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_ai_ig_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode([
                                'is_inquiry' => true,
                                'public_comment_reply' => 'أهلاً بك يا أحمد! تم الرد على الخاص بالتفاصيل كاملة، يسعدنا تواصلك دائماً 😊',
                                'private_reply' => 'أهلاً وسهلاً بك يا أحمد 🌸 بخصوص عرض وجبة العائلة، السعر بعد الخصم 250 جنيه والتوصيل مجاني اليوم!',
                            ], JSON_UNESCAPED_UNICODE),
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841400000000000',
        'access_token' => 'EAAG_fake_ig_token',
        'msg_number' => 10,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'status' => 'active',
        'ai_context' => 'مطعم مشويات ومأكولات شرقية.',
    ]);

    $response = $this->postJson('/api/instagram-comments-webhook', [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => $item->instagram_id,
                'changes' => [
                    [
                        'field' => 'comments',
                        'value' => [
                            'id' => 'comment_inquiry_ig_200',
                            'media' => ['id' => 'media_ig_200'],
                            'from' => [
                                'id' => 'customer_igsid_2',
                                'username' => 'ahmed_wego',
                            ],
                            'text' => 'تفاصيل وسعر العرض كام؟',
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
        'instagram_sent' => true,
    ]);

    // Check msg_number decremented from 10 to 9
    expect($item->fresh()->msg_number)->toBe(9);

    // Check MsgSend recorded
    expect(MsgSend::where('instagram_item_id', $item->id)->where('channel', 'instagram')->count())->toBe(1);

    // Check Chat recorded for customer on Instagram channel
    $chat = Chat::where('instagram_item_id', $item->id)
        ->where('channel', 'instagram')
        ->latest('id')
        ->first();

    expect($chat)->not->toBeNull();
    expect($chat->message)->toContain('أهلاً وسهلاً بك يا أحمد');
    expect($chat->sender_type)->toBe('bot');
    expect($chat->instagram_sender_id)->toBe('customer_igsid_2');

    Event::assertDispatched(InstagramEvent::class);
});

test('instagram comments webhook processes non-inquiry comment (appreciation) and only replies publicly', function () {
    Http::fake([
        'https://graph.facebook.com/*/replies' => Http::response([
            'id' => 'ig_reply_comment_300',
        ], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_ai_ig_2',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode([
                                'is_inquiry' => false,
                                'public_comment_reply' => 'شكراً جزيلاً لذوقك يا سارة! نتشرف بخدمتك دائماً ❤️',
                                'private_reply' => null,
                            ], JSON_UNESCAPED_UNICODE),
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841400000000000',
        'access_token' => 'EAAG_fake_ig_token',
        'msg_number' => 5,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/instagram-comments-webhook', [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => $item->instagram_id,
                'changes' => [
                    [
                        'field' => 'comments',
                        'value' => [
                            'id' => 'comment_praise_ig_300',
                            'media' => ['id' => 'media_ig_300'],
                            'from' => [
                                'id' => 'customer_igsid_3',
                                'username' => 'sara_ali',
                            ],
                            'text' => 'ما شاء الله المكان والخدمة ممتازة',
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
        'instagram_sent' => false,
    ]);

    // Check msg_number decremented from 5 to 4
    expect($item->fresh()->msg_number)->toBe(4);

    // No private chat recorded because it wasn't an inquiry
    expect(Chat::where('instagram_item_id', $item->id)->count())->toBe(0);
});

test('instagram comments webhook automatically refreshes token on 401 expired token and retries', function () {
    $tokenService = Mockery::mock(MetaPageTokenService::class);
    $tokenService->shouldReceive('isTokenExpiredError')->andReturn(true);
    $tokenService->shouldReceive('refreshInstagramItemToken')->andReturn('fresh_refreshed_ig_token');
    app()->instance(MetaPageTokenService::class, $tokenService);

    $callCount = 0;
    Http::fake([
        'https://graph.facebook.com/*/replies' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Http::response([
                    'error' => [
                        'message' => 'Error validating access token: Session has expired',
                        'type' => 'OAuthException',
                        'code' => 190,
                        'error_subcode' => 463,
                    ],
                ], 401);
            }

            return Http::response(['id' => 'refreshed_ig_reply_id'], 200);
        },
        'https://graph.facebook.com/*/me/messages' => Http::response(['recipient_id' => 'customer_ig_retry', 'message_id' => 'mid_ig_retry'], 200),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841400000000000',
        'msg_number' => 0, // Fallback branch
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/instagram-comments-webhook', [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => $item->instagram_id,
                'changes' => [
                    [
                        'field' => 'comments',
                        'value' => [
                            'id' => 'comment_expired_retry_ig_1',
                            'media' => ['id' => 'media_ig_retry'],
                            'from' => [
                                'id' => 'customer_ig_retry',
                                'username' => 'mahmoud_retry',
                            ],
                            'text' => 'ممكن تفاصيل؟',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson([
        'status' => 'fallback_sent',
        'comment_sent' => true,
        'instagram_sent' => true,
    ]);

    expect($callCount)->toBe(2);
});

test('instagram comments webhook matches single active account when entry id differs and updates instagram_id', function () {
    Http::fake([
        'https://graph.facebook.com/*/replies' => Http::response(['id' => 'ig_reply_auto_1'], 200),
        'https://graph.facebook.com/*/me/messages' => Http::response(['recipient_id' => 'customer_ig_auto', 'message_id' => 'mid_auto_1'], 200),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841449192689340', // Different from incoming entry id
        'access_token' => 'EAAG_fake_token',
        'msg_number' => 0, // Fallback branch
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(),
        'status' => 'active',
    ]);

    $incomingEntryId = '17841401051588301';

    $response = $this->postJson('/api/instagram-comments-webhook', [
        'object' => 'instagram',
        'entry' => [
            [
                'id' => $incomingEntryId,
                'changes' => [
                    [
                        'field' => 'comments',
                        'value' => [
                            'id' => 'comment_diff_id_1',
                            'media' => ['id' => 'media_diff_1'],
                            'from' => [
                                'id' => 'customer_ig_auto',
                                'username' => 'olaallaamm',
                            ],
                            'text' => 'test',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson([
        'status' => 'fallback_sent',
        'comment_sent' => true,
        'instagram_sent' => true,
    ]);

    // Check that instagram_id in DB was auto-updated to the incoming entry ID
    expect($item->fresh()->instagram_id)->toBe($incomingEntryId);
});
