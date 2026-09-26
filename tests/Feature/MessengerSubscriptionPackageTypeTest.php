<?php

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'role' => 'user',
        'facebook_access_token' => 'fake_fb_token_123',
    ]);

    Sanctum::actingAs($this->user);
});

test('messenger subscription accepts package with type face', function () {
    $package = Package::factory()->create([
        'type' => 'face',
        'price' => 100,
        'msg_number' => 500,
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'data' => [
                [
                    'id' => 'page_123',
                    'name' => 'My Face Page',
                    'access_token' => 'page_access_token_abc',
                ],
            ],
        ], 200),
    ]);

    $response = $this->postJson('/api/user/messenger/orders', [
        'page_id' => 'page_123',
        'package_id' => $package->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.package.id', $package->id);

    $this->assertDatabaseHas('orders', [
        'user_id' => $this->user->id,
        'package_id' => $package->id,
        'channel' => 'messenger',
    ]);
});

test('messenger subscription accepts package with type all', function () {
    $package = Package::factory()->create([
        'type' => 'all',
        'price' => 200,
        'msg_number' => 1500,
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'data' => [
                [
                    'id' => 'page_456',
                    'name' => 'Omni Page',
                    'access_token' => 'page_access_token_def',
                ],
            ],
        ], 200),
    ]);

    $response = $this->postJson('/api/user/messenger/orders', [
        'page_id' => 'page_456',
        'package_id' => $package->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.package.id', $package->id);
});

test('messenger subscription rejects package with type whats', function () {
    $package = Package::factory()->create([
        'type' => 'whats',
        'price' => 100,
        'msg_number' => 500,
    ]);

    $response = $this->postJson('/api/user/messenger/orders', [
        'page_id' => 'page_789',
        'package_id' => $package->id,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['package_id']);
});
