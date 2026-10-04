<?php

use App\Models\Chat;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('unauthenticated users cannot access all_chats', function () {
    $this->getJson('/api/user/all_chats')->assertUnauthorized();
});

test('authenticated user with facebook token gets messenger and instagram pages with profile pictures', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'facebook_access_token' => 'mock_user_facebook_token_123',
    ]);

    // Create a local whats item
    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01012345678',
        'phone_number_id' => '9988776655',
        'access_token' => 'mock_whats_token_123',
    ]);

    // Create local messenger account and instagram item
    MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_111',
        'page_name' => 'Burger House Page',
    ]);

    InstagramItem::factory()->create([
        'user_id' => $user->id,
        'instagram_id' => 'ig_222',
        'username' => 'burgerhouse_official',
    ]);

    // Fake Meta Graph API calls:
    // 1) /me/accounts for Facebook Pages & Instagram
    // 2) /{phone_number_id}/whatsapp_business_profile for WhatsApp profile picture
    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                [
                    'id' => 'page_111',
                    'name' => 'Burger House Page',
                    'category' => 'Fast Food Restaurant',
                    'picture' => [
                        'data' => [
                            'url' => 'https://cdn.facebook.com/images/page_111_avatar.jpg',
                            'is_silhouette' => false,
                        ],
                    ],
                    'instagram_business_account' => [
                        'id' => 'ig_222',
                        'username' => 'burgerhouse_official',
                        'name' => 'Burger House IG',
                        'profile_picture_url' => 'https://cdn.instagram.com/images/ig_222_avatar.jpg',
                    ],
                ],
            ],
        ], 200),
        'https://graph.facebook.com/*/9988776655/whatsapp_business_profile*' => Http::response([
            'data' => [
                [
                    'profile_picture_url' => 'https://pps.whatsapp.net/v/t61.24694/whats_avatar.jpg',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/user/all_chats');

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'facebook_connected' => true,
        ]);

    $data = $response->json();

    // Verify Facebook Page has profile_picture_url and unread_count
    expect($data['messenger_pages'])->toHaveCount(1);
    expect($data['messenger_pages'][0]['page_id'])->toBe('page_111');
    expect($data['messenger_pages'][0]['profile_picture_url'])->toBe('https://cdn.facebook.com/images/page_111_avatar.jpg');
    expect($data['messenger_pages'][0]['unread_count'])->toBe(0);

    // Verify Instagram Page has profile_picture_url and unread_count
    expect($data['instagram_pages'])->toHaveCount(1);
    expect($data['instagram_pages'][0]['instagram_id'])->toBe('ig_222');
    expect($data['instagram_pages'][0]['profile_picture_url'])->toBe('https://cdn.instagram.com/images/ig_222_avatar.jpg');
    expect($data['instagram_pages'][0]['unread_count'])->toBe(0);

    // Verify WhatsApp Account has profile_picture_url and unread_count
    expect($data['whats_accounts'])->toHaveCount(1);
    expect($data['whats_accounts'][0]['id'])->toBe($whatsItem->id);
    expect($data['whats_accounts'][0]['profile_picture_url'])->toBe('https://pps.whatsapp.net/v/t61.24694/whats_avatar.jpg');
    expect($data['whats_accounts'][0]['unread_count'])->toBe(0);
    expect($data['total_unread_count'])->toBe(0);
});

test('user without facebook token still receives whats accounts and fallback db accounts', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'facebook_access_token' => null,
    ]);

    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01099887766',
        'phone_number_id' => null,
    ]);

    $messengerAccount = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'db_page_555',
        'page_name' => 'Local Page',
    ]);

    $response = $this->actingAs($user)->getJson('/api/user/all_chats');

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'facebook_connected' => false,
        ]);

    $data = $response->json();
    expect($data['whats_accounts'])->toHaveCount(1);
    expect($data['whats_accounts'][0]['id'])->toBe($whatsItem->id);
    expect($data['whats_accounts'][0]['profile_picture_url'])->toBeNull();
    expect($data['whats_accounts'][0]['unread_count'])->toBe(0);

    expect($data['messenger_pages'])->toHaveCount(1);
    expect($data['messenger_pages'][0]['page_id'])->toBe('db_page_555');
    expect($data['messenger_pages'][0]['profile_picture_url'])->toBe('https://graph.facebook.com/db_page_555/picture?type=large');
    expect($data['messenger_pages'][0]['unread_count'])->toBe(0);
    expect($data['total_unread_count'])->toBe(0);
});

test('all_chats returns accurate unread_count for each page and total_unread_count', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'facebook_access_token' => null,
    ]);

    $otherUser = User::factory()->create();

    $messengerAccount = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'fb_page_100',
        'page_name' => 'FB Page 1',
    ]);

    $instagramItem = InstagramItem::factory()->create([
        'user_id' => $user->id,
        'instagram_id' => 'ig_acc_200',
        'username' => 'ig_page_1',
    ]);

    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '01011112222',
    ]);

    // Create unread chats for Messenger (2 unread, 1 read)
    Chat::create([
        'user_id' => $user->id,
        'messenger_account_id' => $messengerAccount->id,
        'message' => 'Unread Msg 1',
        'is_read' => false,
        'channel' => 'messenger',
    ]);
    Chat::create([
        'user_id' => $user->id,
        'messenger_account_id' => $messengerAccount->id,
        'message' => 'Unread Msg 2',
        'is_read' => false,
        'channel' => 'messenger',
    ]);
    Chat::create([
        'user_id' => $user->id,
        'messenger_account_id' => $messengerAccount->id,
        'message' => 'Read Msg 1',
        'is_read' => true,
        'channel' => 'messenger',
    ]);

    // Create unread chats for Instagram (3 unread, 1 read)
    Chat::create([
        'user_id' => $user->id,
        'instagram_item_id' => $instagramItem->id,
        'message' => 'IG Unread 1',
        'is_read' => false,
        'channel' => 'instagram',
    ]);
    Chat::create([
        'user_id' => $user->id,
        'instagram_item_id' => $instagramItem->id,
        'message' => 'IG Unread 2',
        'is_read' => false,
        'channel' => 'instagram',
    ]);
    Chat::create([
        'user_id' => $user->id,
        'instagram_item_id' => $instagramItem->id,
        'message' => 'IG Unread 3',
        'is_read' => false,
        'channel' => 'instagram',
    ]);
    Chat::create([
        'user_id' => $user->id,
        'instagram_item_id' => $instagramItem->id,
        'message' => 'IG Read 1',
        'is_read' => true,
        'channel' => 'instagram',
    ]);

    // Create unread chats for WhatsApp (1 unread)
    Chat::create([
        'user_id' => $user->id,
        'whats_item_id' => $whatsItem->id,
        'phone' => '01011112222',
        'message' => 'WA Unread 1',
        'is_read' => false,
        'channel' => 'whatsapp',
    ]);

    // Other user's chats should not be counted
    Chat::create([
        'user_id' => $otherUser->id,
        'messenger_account_id' => $messengerAccount->id,
        'message' => 'Other User Msg',
        'is_read' => false,
        'channel' => 'messenger',
    ]);

    $response = $this->actingAs($user)->getJson('/api/user/all_chats');

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'total_unread_count' => 6, // 2 (FB) + 3 (IG) + 1 (WA) = 6
        ]);

    $data = $response->json();

    expect($data['messenger_pages'][0]['unread_count'])->toBe(2);
    expect($data['instagram_pages'][0]['unread_count'])->toBe(3);
    expect($data['whats_accounts'][0]['unread_count'])->toBe(1);
    expect($data['total_unread_count'])->toBe(6);
});

test('all_chats returns messenger_pages as sequential JSON array when filtering unregistered pages', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'facebook_access_token' => 'mock_token_with_many_pages',
    ]);

    // Only pages 0 and 2 are registered in DB; page 1 is not registered
    MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_0',
        'page_name' => 'Registered Page 0',
    ]);
    MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_2',
        'page_name' => 'Registered Page 2',
    ]);

    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                ['id' => 'page_0', 'name' => 'Registered Page 0', 'picture' => ['data' => ['url' => 'https://example.com/0.jpg']]],
                ['id' => 'page_1', 'name' => 'Unregistered Page 1', 'picture' => ['data' => ['url' => 'https://example.com/1.jpg']]],
                ['id' => 'page_2', 'name' => 'Registered Page 2', 'picture' => ['data' => ['url' => 'https://example.com/2.jpg']]],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/user/all_chats');
    $response->assertOk();

    $content = $response->getContent();
    $decoded = json_decode($content, true);

    expect(array_is_list($decoded['messenger_pages']))->toBeTrue();
    expect($decoded['messenger_pages'])->toHaveCount(2);
    expect($decoded['messenger_pages'][0]['page_id'])->toBe('page_0');
    expect($decoded['messenger_pages'][1]['page_id'])->toBe('page_2');
    expect(array_is_list($decoded['instagram_pages']))->toBeTrue();
    expect(array_is_list($decoded['whats_accounts']))->toBeTrue();
});
