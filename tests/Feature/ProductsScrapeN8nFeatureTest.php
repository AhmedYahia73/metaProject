<?php

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\TikTokAccount;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

test('products:scrape-n8n command is scheduled daily at 16:00 with Cairo timezone', function () {
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    $event = $events->first(function ($e) {
        return str_contains($e->command, 'products:scrape-n8n');
    });

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 16 * * *');
    expect($event->timezone)->toBe('Africa/Cairo');
});

test('products:scrape-n8n fails if webhook url is missing and not dry-run', function () {
    Config::set('services.n8n.webhook_url', null);

    $this->artisan('products:scrape-n8n')
        ->expectsOutputToContain('Error: N8N_SCRAPE_WEBHOOK_URL is not configured')
        ->assertFailed();
});

test('products:scrape-n8n dry-run lists accounts without making HTTP requests', function () {
    Config::set('services.n8n.webhook_url', 'https://n8n.test/webhook/scrape');
    Http::fake();

    $user = User::factory()->create();

    InstagramItem::factory()->create([
        'user_id' => $user->id,
        'api_link' => null,
        'login_link' => 'https://store.test/login',
        'user_name' => 'admin_user',
        'password' => 'secret_pass',
        'products_link' => 'https://store.test/products',
    ]);

    $this->artisan('products:scrape-n8n --dry-run')
        ->expectsOutputToContain('[DRY-RUN] Would dispatch Instagram')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('products:scrape-n8n ignores accounts with api_link or missing credentials', function () {
    Config::set('services.n8n.webhook_url', 'https://n8n.test/webhook/scrape');
    Http::fake([
        'https://direct-api.test/*' => Http::response([], 200),
        'https://n8n.test/*' => Http::response([], 200),
    ]);

    $user = User::factory()->create();

    // Account 1: Has api_link -> SHOULD BE IGNORED by scraper
    MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'api_link' => 'https://direct-api.test',
        'login_link' => 'https://store.test/login',
        'user_name' => 'admin_user',
        'password' => 'secret_pass',
        'products_link' => 'https://store.test/products',
    ]);

    // Account 2: Missing password -> SHOULD BE IGNORED
    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'api_link' => null,
        'login_link' => 'https://store.test/login',
        'user_name' => 'admin_user',
        'password' => null,
        'products_link' => 'https://store.test/products',
    ]);

    $this->artisan('products:scrape-n8n')
        ->assertSuccessful();

    // Ensure no requests were dispatched to n8n
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'n8n.test');
    });
});

test('products:scrape-n8n dispatches matching accounts across all channels to n8n webhook', function () {
    Config::set('services.n8n.webhook_url', 'https://n8n.test/webhook/scrape');
    Config::set('services.n8n.webhook_secret', 'test-secret-token');

    Http::fake([
        'https://n8n.test/webhook/scrape' => Http::response(['status' => 'queued'], 200),
    ]);

    $user = User::factory()->create();

    $instagram = InstagramItem::factory()->create([
        'user_id' => $user->id,
        'api_link' => null,
        'login_link' => 'https://store.test/login',
        'user_name' => 'admin_ig',
        'password' => 'pass_ig',
        'products_link' => 'https://store.test/products',
    ]);

    $whats = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'api_link' => null,
        'login_link' => 'https://pos.test/login',
        'user_name' => 'admin_whats',
        'password' => 'pass_whats',
        'products_link' => 'https://pos.test/items',
    ]);

    $this->artisan('products:scrape-n8n')
        ->assertSuccessful();

    Http::assertSent(function ($request) use ($instagram, $whats) {
        $body = $request->data();

        return $request->url() === 'https://n8n.test/webhook/scrape'
            && $request->hasHeader('X-Internal-Token', 'test-secret-token')
            && in_array($body['channel'], ['instagram', 'whats'])
            && in_array($body['account_id'], [$instagram->id, $whats->id]);
    });
});

test('internal webhook callback rejects request when token is invalid or missing', function () {
    Config::set('services.n8n.webhook_secret', 'correct-secret-token');

    $response = $this->postJson('/api/internal/channel-products/webhook', [
        'channel' => 'instagram',
        'account_id' => 1,
        'status' => 'success',
        'data' => [],
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('status', false)
        ->assertJsonPath('message', 'Unauthorized: Invalid internal token');

    $responseWithWrongToken = $this->withHeader('X-Internal-Token', 'wrong-token')
        ->postJson('/api/internal/channel-products/webhook', [
            'channel' => 'instagram',
            'account_id' => 1,
            'status' => 'success',
            'data' => [],
        ]);

    $responseWithWrongToken->assertUnauthorized();
});

test('internal webhook callback returns 404 when target account does not exist', function () {
    Config::set('services.n8n.webhook_secret', 'valid-token');

    $response = $this->withHeader('X-Internal-Token', 'valid-token')
        ->postJson('/api/internal/channel-products/webhook', [
            'channel' => 'telegram',
            'account_id' => 999999,
            'status' => 'success',
            'data' => [],
        ]);

    $response->assertNotFound()
        ->assertJsonPath('status', false);
});

test('internal webhook callback updates user_data on success for target channel', function () {
    Config::set('services.n8n.webhook_secret', 'valid-token');

    $user = User::factory()->create();

    $tiktok = TikTokAccount::factory()->create([
        'user_id' => $user->id,
        'api_link' => null,
        'login_link' => 'https://shop.test/login',
        'user_name' => 'tiktok_seller',
        'password' => 'secret123',
        'products_link' => 'https://shop.test/products',
        'user_data' => null,
    ]);

    $scrapedProducts = [
        [
            'id' => 1,
            'name' => 'منتج كشط تجريبي',
            'price' => 150,
            'description' => 'وصف المنتج التجريبي',
        ],
        [
            'id' => 2,
            'name' => 'منتج ثان',
            'price' => 200,
            'description' => 'وصف ثان',
        ],
    ];

    $response = $this->withHeader('X-Internal-Token', 'valid-token')
        ->postJson('/api/internal/channel-products/webhook', [
            'channel' => 'tiktok',
            'account_id' => $tiktok->id,
            'status' => 'success',
            'data' => $scrapedProducts,
        ]);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('products_count', 2);

    $tiktok->refresh();
    expect($tiktok->user_data)->toBeArray();
    expect($tiktok->user_data)->toHaveCount(2);
    expect($tiktok->user_data[0]['name'])->toBe('منتج كشط تجريبي');
    expect($tiktok->user_data[0]['price'])->toBe(150);
});

test('internal webhook callback preserves existing user_data when status is failed', function () {
    Config::set('services.n8n.webhook_secret', 'valid-token');

    $user = User::factory()->create();

    $initialData = [
        ['id' => 1, 'name' => 'Old Product', 'price' => 50],
    ];

    $messenger = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'api_link' => null,
        'user_data' => $initialData,
    ]);

    $response = $this->withHeader('X-Internal-Token', 'valid-token')
        ->postJson('/api/internal/channel-products/webhook', [
            'channel' => 'messenger',
            'account_id' => $messenger->id,
            'status' => 'failed',
            'error_message' => 'Login credentials invalid or expired',
        ]);

    $response->assertOk()
        ->assertJsonPath('status', true);

    $messenger->refresh();
    expect($messenger->user_data)->toBeArray();
    expect($messenger->user_data)->toBe($initialData);
});
