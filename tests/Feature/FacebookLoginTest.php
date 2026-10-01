<?php

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Facebook Connect / Link
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated user cannot connect facebook', function () {
    $response = $this->postJson('/api/auth/facebook', [
        'access_token' => 'dummy_token',
    ]);

    $response->assertUnauthorized();
});

test('facebook connect fails when access token is missing', function () {
    $user = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/auth/facebook', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['access_token']);
});

test('facebook connect fails when graph api returns error', function () {
    $user = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($user);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid OAuth access token.',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], 401),
    ]);

    $response = $this->postJson('/api/auth/facebook', [
        'access_token' => 'invalid_token',
    ]);

    $response->assertUnauthorized()
        ->assertJson(['status' => false]);
});

test('authenticated user can link facebook account and updates access token', function () {
    $user = User::factory()->create([
        'email' => 'regular_user@example.com',
        'facebook_id' => null,
        'facebook_access_token' => null,
        'role' => 'user',
    ]);

    Sanctum::actingAs($user);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/me/accounts')) {
            return Http::response(['data' => []], 200);
        }
        if (str_contains($request->url(), '/me')) {
            return Http::response([
                'id' => '999888777',
                'name' => 'FB Linked Name',
                'email' => 'fb_different_email@example.com',
            ], 200);
        }

        return Http::response([], 200);
    });

    $response = $this->postJson('/api/auth/facebook', [
        'access_token' => 'new_linked_fb_token',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'message' => 'Account linked to Facebook successfully.',
        ])
        ->assertJsonPath('data.is_new', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'facebook_id' => '999888777',
        'facebook_access_token' => 'new_linked_fb_token',
    ]);
});

test('connecting facebook automatically refreshes page and instagram tokens', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'facebook_id' => 'user_fb_id_123',
    ]);

    $messengerAccount = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_123',
        'page_access_token' => 'old_page_token',
    ]);

    $instagramItem = InstagramItem::factory()->create([
        'user_id' => $user->id,
        'instagram_id' => 'ig_123',
        'access_token' => 'old_ig_token',
    ]);

    Sanctum::actingAs($user);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/me/accounts')) {
            return Http::response([
                'data' => [
                    [
                        'id' => 'page_123',
                        'name' => 'My Restaurant Page',
                        'access_token' => 'fresh_page_token_abc',
                        'instagram_business_account' => [
                            'id' => 'ig_123',
                            'name' => 'Restaurant IG',
                        ],
                    ],
                ],
            ], 200);
        }
        if (str_contains($request->url(), '/me')) {
            return Http::response([
                'id' => 'user_fb_id_123',
                'name' => 'John Doe',
            ], 200);
        }

        return Http::response([], 200);
    });

    $response = $this->postJson('/api/auth/facebook', [
        'access_token' => 'fresh_user_token_xyz',
    ]);

    $response->assertOk();

    // Verify user token updated
    expect($user->fresh()->facebook_access_token)->toBe('fresh_user_token_xyz');

    // Verify messenger account page token was updated
    expect($messengerAccount->fresh()->page_access_token)->toBe('fresh_page_token_abc');

    // Verify instagram item token was updated
    expect($instagramItem->fresh()->access_token)->toBe('fresh_page_token_abc');
});
