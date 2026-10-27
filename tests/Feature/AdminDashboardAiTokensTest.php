<?php

use App\Models\DailyToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Cache::flush();
});

test('admin dashboard includes ai_remaining_tokens in data and exactly once in the response', function () {
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response(
            ['choices' => [['message' => ['content' => 'hello']]]],
            200,
            ['x-ratelimit-remaining-tokens' => '125000']
        ),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/dashboard');

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'active_order',
                'used',
                'remaining',
                'ai_remaining_tokens',
                'period' => ['from', 'to'],
                'overview',
            ],
        ])
        ->assertJsonPath('data.ai_remaining_tokens', 125000);

    // Verify ai_remaining_tokens appears exactly once in JSON response
    $jsonString = $response->getContent();
    $occurrences = substr_count($jsonString, '"ai_remaining_tokens"');
    expect($occurrences)->toBe(1);
});

test('admin dashboard returns 0 when openai quota is exhausted', function () {
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'error' => [
                'message' => 'You have no credits remaining.',
                'type' => 'insufficient_quota',
                'code' => 'credit_balance_exhausted',
            ],
        ], 429),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/dashboard');

    $response->assertOk()
        ->assertJsonPath('data.ai_remaining_tokens', 0);
});

test('admin dashboard respects setting override for ai_remaining_tokens', function () {
    Setting::create([
        'name' => 'ai_remaining_tokens',
        'value' => '9999',
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/dashboard');

    $response->assertOk()
        ->assertJsonPath('data.ai_remaining_tokens', 9999);
});

test('admin dashboard dynamically decrements ai_remaining_tokens when tokens are consumed', function () {
    Setting::create([
        'name' => 'ai_total_tokens',
        'value' => '50000',
    ]);

    // Simulate consumed tokens in daily_tokens
    DailyToken::create([
        'date' => now()->toDateString(),
        'tokens' => 12500,
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/dashboard');

    $response->assertOk()
        ->assertJsonPath('data.ai_remaining_tokens', 37500)
        ->assertJsonPath('data.ai_total_tokens', 50000)
        ->assertJsonPath('data.ai_used_tokens', 12500);
});

test('admin can view and update ai token budget via settings endpoints', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);

    // 1. Set total tokens
    $postResponse = $this->postJson('/api/admin/settings/ai-tokens', [
        'total_tokens' => 100000,
    ]);

    $postResponse->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'total_tokens' => 100000,
                'remaining_tokens' => 100000,
            ],
        ]);

    // 2. Get tokens
    $getResponse = $this->getJson('/api/admin/settings/ai-tokens');

    $getResponse->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'total_tokens' => 100000,
                'remaining_tokens' => 100000,
            ],
        ]);
});
