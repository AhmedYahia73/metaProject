<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Facebook Login / Signup
// ─────────────────────────────────────────────────────────────────────────────

test('facebook login fails when access token is missing', function () {
    $response = $this->postJson('/api/auth/facebook', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['access_token']);
});

test('facebook login fails when graph api returns error', function () {
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

test('facebook login creates new user when facebook_id not found', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'id' => '111222333',
            'name' => 'Ahmed Yahia',
            'email' => 'ahmed@example.com',
        ], 200),
    ]);

    $response = $this->postJson('/api/auth/facebook', [
        'access_token' => 'valid_fb_token',
    ]);

    $response->assertOk()
        ->assertJson(['status' => true])
        ->assertJsonPath('data.is_new', true)
        ->assertJsonStructure(['data' => ['token', 'user']]);

    $this->assertDatabaseHas('users', [
        'facebook_id' => '111222333',
        'email' => 'ahmed@example.com',
        'role' => 'user',
    ]);
});

test('facebook login links to existing user with same email', function () {
    $existing = User::factory()->create([
        'email' => 'existing@example.com',
        'facebook_id' => null,
        'role' => 'user',
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'id' => '444555666',
            'name' => 'Existing User',
            'email' => 'existing@example.com',
        ], 200),
    ]);

    $response = $this->postJson('/api/auth/facebook', [
        'access_token' => 'valid_fb_token',
    ]);

    $response->assertOk()
        ->assertJson(['status' => true])
        ->assertJsonPath('data.is_new', false);

    $this->assertDatabaseHas('users', [
        'id' => $existing->id,
        'facebook_id' => '444555666',
    ]);

    // No duplicate user created
    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
});

test('facebook login returns token for returning user', function () {
    $user = User::factory()->create([
        'facebook_id' => '777888999',
        'facebook_access_token' => 'old_token',
        'role' => 'user',
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'id' => '777888999',
            'name' => $user->name,
        ], 200),
    ]);

    $response = $this->postJson('/api/auth/facebook', [
        'access_token' => 'refreshed_fb_token',
    ]);

    $response->assertOk()
        ->assertJson(['status' => true])
        ->assertJsonPath('data.is_new', false);

    // Token should be updated
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'facebook_access_token' => 'refreshed_fb_token',
    ]);
});
