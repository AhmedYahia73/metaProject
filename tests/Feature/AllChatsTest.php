<?php

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

    // Verify Facebook Page has profile_picture_url
    expect($data['messenger_pages'])->toHaveCount(1);
    expect($data['messenger_pages'][0]['page_id'])->toBe('page_111');
    expect($data['messenger_pages'][0]['profile_picture_url'])->toBe('https://cdn.facebook.com/images/page_111_avatar.jpg');

    // Verify Instagram Page has profile_picture_url
    expect($data['instagram_pages'])->toHaveCount(1);
    expect($data['instagram_pages'][0]['instagram_id'])->toBe('ig_222');
    expect($data['instagram_pages'][0]['profile_picture_url'])->toBe('https://cdn.instagram.com/images/ig_222_avatar.jpg');

    // Verify WhatsApp Account has profile_picture_url
    expect($data['whats_accounts'])->toHaveCount(1);
    expect($data['whats_accounts'][0]['id'])->toBe($whatsItem->id);
    expect($data['whats_accounts'][0]['profile_picture_url'])->toBe('https://pps.whatsapp.net/v/t61.24694/whats_avatar.jpg');
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

    expect($data['messenger_pages'])->toHaveCount(1);
    expect($data['messenger_pages'][0]['page_id'])->toBe('db_page_555');
    expect($data['messenger_pages'][0]['profile_picture_url'])->toBe('https://graph.facebook.com/db_page_555/picture?type=large');
});
