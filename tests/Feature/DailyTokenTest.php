<?php

use App\Events\MessengerEvent;
use App\Models\DailyToken;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

test('it records and increments daily tokens for WhatsApp item', function () {
    $whatsItem = WhatsItem::factory()->create();

    // First call: creates new record for today
    $record1 = DailyToken::recordForWhats($whatsItem->id, 150);

    expect($record1)->toBeInstanceOf(DailyToken::class)
        ->and($record1->whats_item_id)->toBe($whatsItem->id)
        ->and($record1->date->toDateString())->toBe(now()->toDateString())
        ->and((int) $record1->tokens)->toBe(150);

    // Second call on the same day: increments tokens
    $record2 = DailyToken::recordForWhats($whatsItem->id, 250);

    $record2->refresh();
    expect((int) $record2->tokens)->toBe(400)
        ->and(DailyToken::where('whats_item_id', $whatsItem->id)->count())->toBe(1);
});

test('it records and increments daily tokens for Messenger account', function () {
    $messengerAccount = MessengerAccount::factory()->create();

    // First call
    $record1 = DailyToken::recordForMessenger($messengerAccount->id, 200);

    expect($record1)->toBeInstanceOf(DailyToken::class)
        ->and($record1->messenger_account_id)->toBe($messengerAccount->id)
        ->and((int) $record1->tokens)->toBe(200);

    // Second call
    $record2 = DailyToken::recordForMessenger($messengerAccount->id, 300);

    $record2->refresh();
    expect((int) $record2->tokens)->toBe(500)
        ->and(DailyToken::where('messenger_account_id', $messengerAccount->id)->count())->toBe(1);
});

test('it records and increments daily tokens for Instagram item', function () {
    $instagramItem = InstagramItem::factory()->create();

    // First call
    $record1 = DailyToken::recordForInstagram($instagramItem->id, 500);

    expect($record1)->toBeInstanceOf(DailyToken::class)
        ->and($record1->instagram_item_id)->toBe($instagramItem->id)
        ->and((int) $record1->tokens)->toBe(500);

    // Second call
    $record2 = DailyToken::recordForInstagram($instagramItem->id, 150);

    $record2->refresh();
    expect((int) $record2->tokens)->toBe(650)
        ->and(DailyToken::where('instagram_item_id', $instagramItem->id)->count())->toBe(1);
});

test('it verifies relationships between DailyToken and channel models', function () {
    $whatsItem = WhatsItem::factory()->create();
    $messengerAccount = MessengerAccount::factory()->create();
    $instagramItem = InstagramItem::factory()->create();

    DailyToken::recordForWhats($whatsItem->id, 100);
    DailyToken::recordForMessenger($messengerAccount->id, 200);
    DailyToken::recordForInstagram($instagramItem->id, 300);

    expect($whatsItem->dailyTokens)->toHaveCount(1)
        ->and($whatsItem->dailyTokens->first()->tokens)->toBe(100)
        ->and($messengerAccount->dailyTokens)->toHaveCount(1)
        ->and($messengerAccount->dailyTokens->first()->tokens)->toBe(200)
        ->and($instagramItem->dailyTokens)->toHaveCount(1)
        ->and($instagramItem->dailyTokens->first()->tokens)->toBe(300);

    $tokenRecord = DailyToken::where('whats_item_id', $whatsItem->id)->first();
    expect($tokenRecord->whatsItem->id)->toBe($whatsItem->id);
});

test('it replies to comment with static text and opens private chat using AI which records tokens', function () {
    Event::fake([MessengerEvent::class]);

    Http::fake([
        'https://graph.facebook.com/*/comments' => Http::response(['id' => 'reply_comment_123'], 200),
        'https://graph.facebook.com/*/me/messages' => Http::response(['recipient_id' => 'psid_123', 'message_id' => 'mid_123'], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode([
                                'is_inquiry' => true,
                                'private_messenger_reply' => 'أهلاً بك يا أحمد، تم الرد على الخاص بخصوص استفسارك!',
                            ]),
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 80,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens' => 45,
                'output_tokens_details' => ['reasoning_tokens' => 0],
                'total_tokens' => 125,
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'page_id' => '999888777',
        'page_access_token' => 'token_123',
        'msg_number' => 10,
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
                            'comment_id' => 'comment_test_1',
                            'from' => [
                                'id' => 'psid_123',
                                'name' => 'أحمد',
                            ],
                            'message' => 'بكام العرض؟',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson([
        'status' => 'success',
        'comment_sent' => true,
        'messenger_sent' => true,
        'public_comment_reply' => 'تم الرد على الخاص يا فندم',
    ]);

    // Check that public comment was posted with fixed text "تم الرد على الخاص يا فندم"
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/comments')
            && ($request['message'] ?? '') === 'تم الرد على الخاص يا فندم';
    });

    // Check that tokens were recorded in DailyToken for MessengerAccount
    $dailyToken = DailyToken::where('messenger_account_id', $account->id)->first();
    expect($dailyToken)->not->toBeNull()
        ->and((int) $dailyToken->tokens)->toBe(125);
});
