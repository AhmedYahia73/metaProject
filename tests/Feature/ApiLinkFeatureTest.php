<?php

use App\Http\Controllers\api\HomeController;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\TikTokAccount;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

test('all channel tables have api_link column', function () {
    expect(Schema::hasColumn('messenger_accounts', 'api_link'))->toBeTrue();
    expect(Schema::hasColumn('whats_items', 'api_link'))->toBeTrue();
    expect(Schema::hasColumn('instagram_items', 'api_link'))->toBeTrue();
    expect(Schema::hasColumn('telegram_accounts', 'api_link'))->toBeTrue();
    expect(Schema::hasColumn('tiktok_accounts', 'api_link'))->toBeTrue();
});

test('models allow fillable api_link', function () {
    $user = User::factory()->create();

    $messenger = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'api_link' => 'https://restaurant-pos.test',
    ]);
    expect($messenger->api_link)->toBe('https://restaurant-pos.test');

    $whats = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'api_link' => 'https://restaurant-pos.test',
    ]);
    expect($whats->api_link)->toBe('https://restaurant-pos.test');

    $instagram = InstagramItem::factory()->create([
        'user_id' => $user->id,
        'api_link' => 'https://restaurant-pos.test',
    ]);
    expect($instagram->api_link)->toBe('https://restaurant-pos.test');

    $telegram = TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'api_link' => 'https://restaurant-pos.test',
    ]);
    expect($telegram->api_link)->toBe('https://restaurant-pos.test');

    $tiktok = TikTokAccount::factory()->create([
        'user_id' => $user->id,
        'api_link' => 'https://restaurant-pos.test',
    ]);
    expect($tiktok->api_link)->toBe('https://restaurant-pos.test');
});

test('admin can store and update messenger account with api_link', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();

    $storeResponse = $this->actingAs($admin)
        ->postJson("/api/admin/users/{$user->id}/messenger-accounts", [
            'page_id' => 'page_test_api_link_1',
            'page_name' => 'Page With API Link',
            'page_access_token' => 'dummy_token',
            'api_link' => 'https://menu.myshop.com',
        ]);

    $storeResponse->assertCreated();
    $account = MessengerAccount::where('page_id', 'page_test_api_link_1')->first();
    expect($account->api_link)->toBe('https://menu.myshop.com');

    // Show
    $showResponse = $this->actingAs($admin)
        ->getJson("/api/admin/users/{$user->id}/messenger-accounts/{$account->id}");
    $showResponse->assertOk()
        ->assertJsonPath('data.api_link', 'https://menu.myshop.com');

    // Update
    $updateResponse = $this->actingAs($admin)
        ->putJson("/api/admin/users/{$user->id}/messenger-accounts/{$account->id}", [
            'api_link' => 'https://menu2.myshop.com',
        ]);
    $updateResponse->assertOk();
    expect($account->fresh()->api_link)->toBe('https://menu2.myshop.com');
});

test('admin can store and update instagram item with api_link', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();

    $storeResponse = $this->actingAs($admin)
        ->postJson("/api/admin/users/{$user->id}/instagram-items", [
            'instagram_id' => 'ig_test_123',
            'access_token' => 'dummy_ig_token',
            'api_link' => 'https://pos.myrestaurant.com',
        ]);

    $storeResponse->assertCreated();
    $item = InstagramItem::where('instagram_id', 'ig_test_123')->first();
    expect($item->api_link)->toBe('https://pos.myrestaurant.com');

    // Show
    $showResponse = $this->actingAs($admin)
        ->getJson("/api/admin/users/{$user->id}/instagram-items/{$item->id}");
    $showResponse->assertOk()
        ->assertJsonPath('data.api_link', 'https://pos.myrestaurant.com');

    // Update
    $updateResponse = $this->actingAs($admin)
        ->putJson("/api/admin/users/{$user->id}/instagram-items/{$item->id}", [
            'api_link' => 'https://pos2.myrestaurant.com',
        ]);
    $updateResponse->assertOk();
    expect($item->fresh()->api_link)->toBe('https://pos2.myrestaurant.com');
});

test('admin can store and update telegram account with api_link', function () {
    Http::fake([
        'https://api.telegram.org/botdummy_bot_token/getMe' => Http::response([
            'ok' => true,
            'result' => [
                'id' => 987654321,
                'is_bot' => true,
                'first_name' => 'Test Bot',
                'username' => 'test_bot',
            ],
        ], 200),
        'https://api.telegram.org/botdummy_bot_token/setWebhook*' => Http::response([
            'ok' => true,
            'result' => true,
        ], 200),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();

    $storeResponse = $this->actingAs($admin)
        ->postJson("/api/admin/users/{$user->id}/telegram-accounts", [
            'bot_token' => 'dummy_bot_token',
            'api_link' => 'https://telegram.pos.com',
        ]);

    $storeResponse->assertCreated();
    $account = TelegramAccount::where('bot_id', '987654321')->first();
    expect($account->api_link)->toBe('https://telegram.pos.com');

    // Show
    $showResponse = $this->actingAs($admin)
        ->getJson("/api/admin/users/{$user->id}/telegram-accounts/{$account->id}");
    $showResponse->assertOk()
        ->assertJsonPath('data.api_link', 'https://telegram.pos.com');

    // Update
    $updateResponse = $this->actingAs($admin)
        ->putJson("/api/admin/users/{$user->id}/telegram-accounts/{$account->id}", [
            'api_link' => 'https://telegram2.pos.com',
        ]);
    $updateResponse->assertOk();
    expect($account->fresh()->api_link)->toBe('https://telegram2.pos.com');
});

test('admin can store and update tiktok account with api_link', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();

    $storeResponse = $this->actingAs($admin)
        ->postJson("/api/admin/users/{$user->id}/tiktok-accounts", [
            'open_id' => 'tiktok_act_123',
            'access_token' => 'tiktok_token_123',
            'api_link' => 'https://tiktok.pos.com',
        ]);

    $storeResponse->assertCreated();
    $account = TikTokAccount::where('open_id', 'tiktok_act_123')->first();
    expect($account->api_link)->toBe('https://tiktok.pos.com');

    // Show
    $showResponse = $this->actingAs($admin)
        ->getJson("/api/admin/users/{$user->id}/tiktok-accounts/{$account->id}");
    $showResponse->assertOk()
        ->assertJsonPath('data.api_link', 'https://tiktok.pos.com');

    // Update
    $updateResponse = $this->actingAs($admin)
        ->putJson("/api/admin/users/{$user->id}/tiktok-accounts/{$account->id}", [
            'api_link' => 'https://tiktok2.pos.com',
        ]);
    $updateResponse->assertOk();
    expect($account->fresh()->api_link)->toBe('https://tiktok2.pos.com');
});

test('user ai_data endpoint supports api_link for messenger', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_user_ai_test',
        'api_link' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/user/messenger/ai_data', [
            'page_id' => 'page_user_ai_test',
            'api_link' => 'https://pos.userlink.com',
        ]);

    $response->assertOk()
        ->assertJsonPath('api_link', 'https://pos.userlink.com');

    expect($account->fresh()->api_link)->toBe('https://pos.userlink.com');
});

test('user ai_data endpoint supports api_link for instagram', function () {
    $user = User::factory()->create(['role' => 'user']);
    $item = InstagramItem::factory()->create([
        'user_id' => $user->id,
        'instagram_id' => 'ig_user_ai_test',
        'api_link' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/user/instagram/ai_data', [
            'instagram_id' => 'ig_user_ai_test',
            'api_link' => 'https://pos.userlink-ig.com',
        ]);

    $response->assertOk()
        ->assertJsonPath('api_link', 'https://pos.userlink-ig.com')
        ->assertJsonPath('data.api_link', 'https://pos.userlink-ig.com');

    expect($item->fresh()->api_link)->toBe('https://pos.userlink-ig.com');
});

test('user ai_data endpoint supports api_link for whats', function () {
    $user = User::factory()->create(['role' => 'user']);
    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'api_link' => 'https://pos.userlink-whats.com',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/user/whats/ai_data/{$item->id}");

    $response->assertOk()
        ->assertJsonPath('api_link', 'https://pos.userlink-whats.com');
});

test('fetchProductsFromApiLink fetches /api/public/products from configured api_link', function () {
    Http::fake([
        'https://restaurant-pos.test/api/public/products' => Http::response([
            'products' => [
                ['id' => 1, 'name' => 'Pizza Margherita', 'price' => 150],
                ['id' => 2, 'name' => 'Burger', 'price' => 120],
            ],
        ], 200),
    ]);

    $controller = app(HomeController::class);
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('fetchProductsFromApiLink');
    $method->setAccessible(true);

    $whatsItem = new WhatsItem(['api_link' => 'https://restaurant-pos.test']);
    $result = $method->invoke($controller, $whatsItem);

    expect($result)->not->toBeNull();
    expect($result)->toContain('Pizza Margherita');
    expect($result)->toContain('Burger');
});

test('buildApiProductsPromptSection injects product guidance into AI prompt', function () {
    Http::fake([
        'https://restaurant-pos.test/api/public/products' => Http::response([
            ['id' => 1, 'name' => 'Shawarma', 'price' => 80],
        ], 200),
    ]);

    $controller = app(HomeController::class);
    $whatsItem = new WhatsItem(['api_link' => 'https://restaurant-pos.test']);

    $section = $controller->buildApiProductsPromptSection($whatsItem);

    expect($section)->toContain('Shawarma');
    expect($section)->toContain('Products API');
});
