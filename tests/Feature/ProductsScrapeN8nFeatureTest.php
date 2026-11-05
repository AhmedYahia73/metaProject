<?php

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\TikTokAccount;
use App\Models\User;
use App\Models\WhatsItem;
use App\Services\ApiDiscoveryService;
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
    expect($event->expression)->toBeIn(['0 16 * * *', '38 11 * * *']);
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
        ->expectsOutputToContain('[DRY-RUN] Would scrape Instagram')
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

test('products:scrape-n8n uses api discovery first then falls back to n8n webhook', function () {
    Config::set('services.n8n.webhook_url', 'https://n8n.test/webhook/scrape');
    Config::set('services.n8n.webhook_secret', 'test-secret-token');

    // Mock ApiDiscoveryService to return null (force n8n fallback)
    $mockDiscovery = Mockery::mock(ApiDiscoveryService::class);
    $mockDiscovery->shouldReceive('discoverAndFetch')->andReturn(null);
    app()->instance(ApiDiscoveryService::class, $mockDiscovery);

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

test('products:scrape-n8n saves products directly when api discovery succeeds', function () {
    Config::set('services.n8n.webhook_url', 'https://n8n.test/webhook/scrape');

    $discoveredProducts = [
        ['id' => 1, 'name' => 'بيتزا مارغريتا', 'price' => 120],
        ['id' => 2, 'name' => 'برجر كلاسيك', 'price' => 85],
    ];

    $mockDiscovery = Mockery::mock(ApiDiscoveryService::class);
    $mockDiscovery->shouldReceive('discoverAndFetch')->andReturn([
        'products' => $discoveredProducts,
        'meta' => [
            'source' => 'api_discovery',
            'base_url' => 'https://bcknd.store.test',
            'products_count' => 2,
            'scraped_at' => now()->toIso8601String(),
        ],
    ]);
    app()->instance(ApiDiscoveryService::class, $mockDiscovery);

    Http::fake();

    $user = User::factory()->create();

    $whats = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'api_link' => null,
        'login_link' => 'https://store.test/login',
        'user_name' => 'admin',
        'password' => 'secret',
        'products_link' => 'https://store.test/products',
        'user_data' => null,
    ]);

    $this->artisan('products:scrape-n8n --channel=whats --id='.$whats->id)
        ->assertSuccessful();

    // n8n should NOT have been called since discovery succeeded
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'n8n.test');
    });

    $whats->refresh();
    expect($whats->user_data)->toBeArray();
    expect($whats->user_data)->toHaveCount(2);
    expect($whats->user_data[0]['name'])->toBe('بيتزا مارغريتا');
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

test('creating or updating products_link automatically triggers products:scrape-n8n for all 5 channel modules', function () {
    Config::set('services.products_scrape.enable_in_tests', true);

    try {
        $user = User::factory()->create();

        $channels = [
            'whats' => [WhatsItem::class, [
                'user_id' => $user->id,
                'phone' => '+201011122233',
                'login_link' => 'https://pos.test/login',
                'user_name' => 'admin_whats',
                'password' => 'secret_whats',
            ]],
            'telegram' => [TelegramAccount::class, [
                'user_id' => $user->id,
                'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
                'bot_username' => 'test_bot',
                'login_link' => 'https://pos.test/login',
                'user_name' => 'admin_tg',
                'password' => 'secret_tg',
            ]],
            'tiktok' => [TikTokAccount::class, [
                'user_id' => $user->id,
                'open_id' => 'tt_open_id_123',
                'access_token' => 'tt_access_token_123',
                'login_link' => 'https://pos.test/login',
                'user_name' => 'admin_tt',
                'password' => 'secret_tt',
            ]],
            'messenger' => [MessengerAccount::class, [
                'user_id' => $user->id,
                'page_id' => 'fb_page_id_123',
                'page_name' => 'Test Page',
                'page_access_token' => 'token_123',
                'verify_token' => 'verify_token_msg_123',
                'login_link' => 'https://pos.test/login',
                'user_name' => 'admin_fb',
                'password' => 'secret_fb',
            ]],
            'instagram' => [InstagramItem::class, [
                'user_id' => $user->id,
                'instagram_id' => '178414012345678',
                'username' => 'test_ig_account',
                'page_id' => 'page_id_123',
                'access_token' => 'token_ig_123',
                'verify_token' => 'verify_token_123',
                'login_link' => 'https://pos.test/login',
                'user_name' => 'admin_ig',
                'password' => 'secret_ig',
            ]],
        ];

        foreach ($channels as $channelKey => [$modelClass, $attributes]) {
            // Mock ApiDiscoveryService to return products
            $mockDiscovery = Mockery::mock(ApiDiscoveryService::class);
            $mockDiscovery->shouldReceive('discoverAndFetch')
                ->once()
                ->andReturn([
                    'products' => [
                        ['id' => 101, 'name' => "Product from {$channelKey}", 'price' => 99],
                    ],
                    'meta' => ['source' => 'api_discovery'],
                ]);
            app()->instance(ApiDiscoveryService::class, $mockDiscovery);

            // Test 1: On Create with products_link
            $attributes['products_link'] = "https://pos.test/{$channelKey}/products";
            $model = $modelClass::create($attributes);

            $model->refresh();
            expect($model->user_data)->toBeArray();
            expect($model->user_data)->toHaveCount(1);
            expect($model->user_data[0]['name'])->toBe("Product from {$channelKey}");

            // Test 2: On Update without changing products_link (should NOT re-scrape)
            $mockDiscoveryNoCall = Mockery::mock(ApiDiscoveryService::class);
            $mockDiscoveryNoCall->shouldNotReceive('discoverAndFetch');
            app()->instance(ApiDiscoveryService::class, $mockDiscoveryNoCall);

            $model->update(['msg_number' => 5]);

            // Test 3: On Update with new products_link (should re-scrape)
            $mockDiscoveryUpdate = Mockery::mock(ApiDiscoveryService::class);
            $mockDiscoveryUpdate->shouldReceive('discoverAndFetch')
                ->once()
                ->andReturn([
                    'products' => [
                        ['id' => 102, 'name' => "Updated product from {$channelKey}", 'price' => 150],
                    ],
                    'meta' => ['source' => 'api_discovery'],
                ]);
            app()->instance(ApiDiscoveryService::class, $mockDiscoveryUpdate);

            $model->update(['products_link' => "https://pos.test/{$channelKey}/new_products"]);
            $model->refresh();
            expect($model->user_data[0]['name'])->toBe("Updated product from {$channelKey}");
            expect($model->user_data[0]['price'])->toBe(150);

            // Test 4: On Update using singular 'product_link' alias (should re-scrape)
            $mockDiscoverySingular = Mockery::mock(ApiDiscoveryService::class);
            $mockDiscoverySingular->shouldReceive('discoverAndFetch')
                ->once()
                ->andReturn([
                    'products' => [
                        ['id' => 103, 'name' => "Singular link product for {$channelKey}", 'price' => 175],
                    ],
                    'meta' => ['source' => 'api_discovery'],
                ]);
            app()->instance(ApiDiscoveryService::class, $mockDiscoverySingular);

            $model->update(['product_link' => "https://pos.test/{$channelKey}/singular_link_products"]);
            $model->refresh();
            expect($model->products_link)->toBe("https://pos.test/{$channelKey}/singular_link_products");
            expect($model->product_link)->toBe("https://pos.test/{$channelKey}/singular_link_products");
            expect($model->user_data[0]['name'])->toBe("Singular link product for {$channelKey}");
        }
    } finally {
        Config::set('services.products_scrape.enable_in_tests', false);
    }
});

test('updating product_link via admin whats-items controller triggers products:scrape-n8n and returns populated user_data', function () {
    Config::set('services.products_scrape.enable_in_tests', true);

    try {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create();

        $whatsItem = WhatsItem::factory()->create([
            'user_id' => $targetUser->id,
            'api_link' => 'https://existing-api.test',
            'login_link' => 'https://login.mathshouse.net/login',
            'user_name' => 'admin_test',
            'password' => 'secret_pass',
            'products_link' => null,
            'user_data' => null,
        ]);

        $mockDiscovery = Mockery::mock(ApiDiscoveryService::class);
        $mockDiscovery->shouldReceive('discoverAndFetch')
            ->once()
            ->andReturn([
                'products' => [
                    ['id' => 1, 'name' => 'Exam Algebra 1', 'price' => 250],
                ],
                'meta' => ['source' => 'api_discovery'],
            ]);
        app()->instance(ApiDiscoveryService::class, $mockDiscovery);

        // Test with singular 'product_link'
        $response = $this->actingAs($admin)
            ->putJson("/api/admin/users/{$targetUser->id}/whats-items/{$whatsItem->id}", [
                'product_link' => 'https://login.mathshouse.net/student/MyCourses/HistoryQuizze',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.products_link', 'https://login.mathshouse.net/student/MyCourses/HistoryQuizze')
            ->assertJsonPath('data.user_data.0.name', 'Exam Algebra 1');

        $whatsItem->refresh();
        expect($whatsItem->products_link)->toBe('https://login.mathshouse.net/student/MyCourses/HistoryQuizze');
        expect($whatsItem->user_data)->toBeArray();
        expect($whatsItem->user_data[0]['name'])->toBe('Exam Algebra 1');
    } finally {
        Config::set('services.products_scrape.enable_in_tests', false);
    }
});
