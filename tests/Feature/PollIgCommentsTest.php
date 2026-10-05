<?php

use App\Events\InstagramEvent;
use App\Models\Chat;
use App\Models\InstagramItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('poll ig comments command reports no active accounts when none exist', function () {
    $this->artisan('ig:poll-comments')
        ->expectsOutput('No active Instagram accounts found to poll.')
        ->assertExitCode(0);
});

test('poll ig comments polls media and comments, and executes auto replies', function () {
    Event::fake([InstagramEvent::class]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841449192689340',
        'username' => 'keeto_store',
        'page_id' => 'page_keeto_123',
        'access_token' => 'token_keeto_abc',
        'status' => 'active',
        'msg_number' => 10,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
    ]);

    Http::fake([
        'https://graph.facebook.com/v21.0/17841449192689340/media*' => Http::response([
            'data' => [
                ['id' => 'media_keeto_1', 'caption' => 'عرض الصيف الكبير على منتجاتنا!'],
            ],
        ], 200),

        'https://graph.facebook.com/v21.0/media_keeto_1/comments*' => Http::response([
            'data' => [
                [
                    'id' => 'comment_poll_101',
                    'text' => 'ممكن تفاصيل؟',
                    'username' => 'customer_poll_user',
                    'timestamp' => now()->toIso8601String(),
                    'from' => [
                        'id' => 'customer_poll_igsid_1',
                        'username' => 'customer_poll_user',
                    ],
                ],
            ],
        ], 200),

        // Public comment reply
        'https://graph.facebook.com/v21.0/comment_poll_101/replies' => Http::response([
            'id' => 'reply_poll_101',
        ], 200),

        // Private reply DM via Instagram Messaging API
        'https://graph.facebook.com/v21.0/me/messages' => Http::response([
            'recipient_id' => 'customer_poll_igsid_1',
            'message_id' => 'mid_poll_101',
        ], 200),
    ]);

    $this->artisan('ig:poll-comments', ['--account' => $item->id])
        ->assertExitCode(0);

    // Verify comment is cached
    expect(Cache::has('ig_comment_replied_comment_poll_101'))->toBeTrue();

    // Verify chat was created
    $chat = Chat::where('instagram_item_id', $item->id)->first();
    expect($chat)->not->toBeNull();
    expect($chat->name)->toBe('customer_poll_user');
    expect($chat->channel)->toBe('instagram');

    // Verify quota decremented
    expect($item->fresh()->msg_number)->toBe(9);
});

test('poll ig comments --skip-existing marks comments as cached without replying', function () {
    $restaurant = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $restaurant->id,
        'instagram_id' => '17841449192689340',
        'username' => 'keeto_store',
        'access_token' => 'token_keeto_abc',
        'status' => 'active',
        'msg_number' => 10,
    ]);

    Http::fake([
        'https://graph.facebook.com/v21.0/17841449192689340/media*' => Http::response([
            'data' => [
                ['id' => 'media_keeto_2', 'caption' => 'عرض خاص'],
            ],
        ], 200),

        'https://graph.facebook.com/v21.0/media_keeto_2/comments*' => Http::response([
            'data' => [
                [
                    'id' => 'comment_old_202',
                    'text' => 'fgf',
                    'username' => 'olaallaamm',
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ], 200),
    ]);

    $this->artisan('ig:poll-comments', ['--account' => $item->id, '--skip-existing' => true])
        ->assertExitCode(0);

    expect(Cache::has('ig_comment_replied_comment_old_202'))->toBeTrue();
    expect(Chat::count())->toBe(0);
});
