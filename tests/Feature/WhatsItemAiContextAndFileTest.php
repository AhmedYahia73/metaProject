<?php

use App\Models\Order;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

test('whats_items table has ai_context and ai_file columns', function () {
    expect(Schema::hasColumn('whats_items', 'ai_context'))->toBeTrue();
    expect(Schema::hasColumn('whats_items', 'ai_file'))->toBeTrue();
});

test('admin can store and update whats item with ai_context and upload ai_file', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($admin);

    $file = UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf');

    $response = $this->postJson("/api/admin/users/{$restaurant->id}/whats-items", [
        'phone' => '01011112222',
        'ai_context' => 'سياق واتساب خاص بالمطعم',
        'ai_file' => $file,
    ]);

    $response->assertCreated();

    $item = WhatsItem::where('phone', '01011112222')->first();
    expect($item)->not->toBeNull();
    expect($item->ai_context)->toBe('سياق واتساب خاص بالمطعم');
    expect($item->ai_file)->not->toBeNull();
    Storage::disk('public')->assertExists($item->ai_file);

    // Update with new file
    $newFile = UploadedFile::fake()->create('new_menu.txt', 50, 'text/plain');
    $oldPath = $item->ai_file;

    $updateResponse = $this->putJson("/api/admin/users/{$restaurant->id}/whats-items/{$item->id}", [
        'ai_context' => 'سياق واتساب محدث',
        'ai_file' => $newFile,
    ]);

    $updateResponse->assertOk();
    $freshItem = $item->fresh();
    expect($freshItem->ai_context)->toBe('سياق واتساب محدث');
    expect($freshItem->ai_file)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($freshItem->ai_file);
});

test('user can store and update their own whats item with ai_context and upload ai_file', function () {
    Storage::fake('public');

    $restaurant = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($restaurant);

    $file = UploadedFile::fake()->create('user_menu.json', 100, 'application/json');

    $response = $this->postJson('/api/user/whats/items', [
        'phone' => '01033334444',
        'ai_context' => 'سياق اليوزر للواتساب',
        'ai_file' => $file,
    ]);

    $response->assertCreated();

    $item = WhatsItem::where('phone', '01033334444')->first();
    expect($item)->not->toBeNull();
    expect($item->ai_context)->toBe('سياق اليوزر للواتساب');
    expect($item->ai_file)->not->toBeNull();
    Storage::disk('public')->assertExists($item->ai_file);

    // Delete item removes file
    $deleteResponse = $this->deleteJson("/api/user/whats/items/{$item->id}");
    $deleteResponse->assertOk();

    Storage::disk('public')->assertMissing($item->ai_file);
    $this->assertDatabaseMissing('whats_items', ['id' => $item->id]);
});

test('admin approving whatsapp order can set ai_context and upload ai_file', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = Package::create([
        'name' => ['ar' => 'باقة واتس', 'en' => 'Whats Package'],
        'type' => 'whats',
        'msg_number' => 150,
        'price' => 70,
        'months' => 1,
    ]);

    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone_status' => 'active',
        'msg_number' => 10,
    ]);

    $order = Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'price' => 70,
        'final_price' => 70,
        'msgs' => 150,
        'status' => 'pending',
        'channel' => 'whatsapp',
        'whats_item_id' => $item->id,
    ]);

    $file = UploadedFile::fake()->create('approved_whats_menu.txt', 80, 'text/plain');

    $response = $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/approve", [
        'ai_context' => 'سياق معتمد من الأدمن للواتس',
        'ai_file' => $file,
    ]);

    $response->assertOk();

    $freshItem = $item->fresh();
    expect($freshItem->ai_context)->toBe('سياق معتمد من الأدمن للواتس');
    expect($freshItem->ai_file)->not->toBeNull();
    expect($freshItem->msg_number)->toBe(160);
    Storage::disk('public')->assertExists($freshItem->ai_file);
});

test('whatsapp webhook uses whats_item ai_context and ai_file in OpenAI prompt without Food tools', function () {
    Http::fake([
        'https://graph.facebook.com/*/messages' => Http::response([
            'messages' => [['id' => 'wamid.123456']],
        ], 200),
    ]);

    $capturedInstructions = null;
    $capturedTools = null;

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_whats_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'أهلاً بك! لدينا برجر لحم بسعر 120 جنيه.',
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);

    $item = WhatsItem::factory()->create([
        'user_id' => $restaurant->id,
        'phone' => '01012345678',
        'phone_number_id' => 'pid_test_webhook',
        'phone_status' => 'active',
        'msg_number' => 20,
        'ai_context' => 'أنت موظف خدمة عملاء خاص بمطعم برجر شوب.',
        'ai_file' => "قائمة الطعام:\n1. برجر لحم: 120 جنيه\n2. برجر دجاج: 110 جنيه",
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة', 'en' => 'Package'],
        'type' => 'whats',
        'msg_number' => 20,
        'price' => 30,
        'months' => 1,
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'price' => 30,
        'final_price' => 30,
        'from' => now()->subDay()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
        'msgs' => 20,
        'status' => 'approved',
        'channel' => 'whatsapp',
        'whats_item_id' => $item->id,
    ]);

    $response = $this->postJson('/api/web-hook', [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'waba_test',
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => 'pid_test_webhook'],
                            'contacts' => [['profile' => ['name' => 'Mohamed'], 'wa_id' => '201099998888']],
                            'messages' => [
                                [
                                    'id' => 'wamid.incoming_1',
                                    'from' => '201099998888',
                                    'text' => ['body' => 'عندكم برجر لحم؟'],
                                    'type' => 'text',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'success', 'whatsapp_sent' => true]);

    OpenAI::assertSent(Responses::class, function (string $method, array $parameters) use (&$capturedInstructions, &$capturedTools) {
        $capturedInstructions = $parameters['instructions'] ?? '';
        $capturedTools = $parameters['tools'] ?? null;

        return true;
    });

    expect($capturedInstructions)->toContain('أنت موظف خدمة عملاء خاص بمطعم برجر شوب.');
    expect($capturedInstructions)->toContain('قائمة الطعام:');
    expect($capturedInstructions)->toContain('برجر لحم: 120 جنيه');
    expect($capturedTools)->toBeNull();
});

test('whatsapp webhook falls back to Setting when whats_item has no ai_context', function () {
    Http::fake([
        'https://graph.facebook.com/*/messages' => Http::response([
            'messages' => [['id' => 'wamid.123456']],
        ], 200),
    ]);

    Setting::updateOrCreate(
        ['name' => 'ai_context'],
        ['value' => 'سياق عام من الإعدادات لكل المطاعم']
    );

    $capturedInstructions = null;

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_whats_2',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'مرحباً بك!',
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);

    $item = WhatsItem::factory()->create([
        'user_id' => $restaurant->id,
        'phone_number_id' => 'pid_test_fallback',
        'phone_status' => 'active',
        'msg_number' => 20,
        'ai_context' => null,
        'ai_file' => null,
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة', 'en' => 'Package'],
        'type' => 'whats',
        'msg_number' => 20,
        'price' => 30,
        'months' => 1,
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'price' => 30,
        'final_price' => 30,
        'from' => now()->subDay()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
        'msgs' => 20,
        'status' => 'approved',
        'channel' => 'whatsapp',
        'whats_item_id' => $item->id,
    ]);

    $response = $this->postJson('/api/web-hook', [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'waba_test',
                'changes' => [
                    [
                        'value' => [
                            'metadata' => ['phone_number_id' => 'pid_test_fallback'],
                            'contacts' => [['profile' => ['name' => 'Customer'], 'wa_id' => '201011112222']],
                            'messages' => [
                                [
                                    'id' => 'wamid.incoming_2',
                                    'from' => '201011112222',
                                    'text' => ['body' => 'مرحبا'],
                                    'type' => 'text',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk();

    OpenAI::assertSent(Responses::class, function (string $method, array $parameters) use (&$capturedInstructions) {
        $capturedInstructions = $parameters['instructions'] ?? '';

        return true;
    });

    expect($capturedInstructions)->toContain('سياق عام من الإعدادات لكل المطاعم');
    expect($capturedInstructions)->not->toContain('بيانات وقائمة المنتجات / الخدمات');
});

test('whats_items table has website_url column', function () {
    expect(Schema::hasColumn('whats_items', 'website_url'))->toBeTrue();
});

test('user and admin can store and update whats item with website_url', function () {
    $restaurant = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($restaurant);

    $response = $this->postJson('/api/user/whats/items', [
        'phone' => '01099998888',
        'website_url' => 'https://myrestaurant.com',
    ]);

    $response->assertCreated();
    $item = WhatsItem::where('phone', '01099998888')->first();
    expect($item->website_url)->toBe('https://myrestaurant.com');

    // Update
    $updateResponse = $this->putJson("/api/user/whats/items/{$item->id}", [
        'website_url' => 'https://myrestaurant.com/new',
    ]);
    $updateResponse->assertOk();
    expect($item->fresh()->website_url)->toBe('https://myrestaurant.com/new');
});

test('admin approving whatsapp order can set website_url', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = Package::create([
        'name' => ['ar' => 'باقة واتس', 'en' => 'Whats Package'],
        'type' => 'whats',
        'msg_number' => 50,
        'price' => 40,
        'months' => 1,
    ]);

    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone_status' => 'active',
        'website_url' => null,
    ]);

    $order = Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'price' => 40,
        'final_price' => 40,
        'msgs' => 50,
        'status' => 'pending',
        'channel' => 'whatsapp',
        'whats_item_id' => $item->id,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/approve", [
        'website_url' => 'https://whats-site.com',
    ]);

    $response->assertOk();
    expect($item->fresh()->website_url)->toBe('https://whats-site.com');
});

test('user whatsapp requestSubscription can optionally pass android_link, ios_link, website_url, ai_context, ai_file with update_image', function () {
    Storage::fake('public');

    $restaurant = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($restaurant);

    $package = Package::create([
        'name' => ['ar' => 'باقة واتس', 'en' => 'Whats Package'],
        'type' => 'whats',
        'msg_number' => 100,
        'price' => 60,
        'months' => 1,
    ]);

    // Pre-create whats item with old file
    $oldFilePath = UploadedFile::fake()->create('old_whats_menu.pdf', 30, 'application/pdf')->store('whats/ai_files', 'public');
    Storage::disk('public')->assertExists($oldFilePath);

    $item = WhatsItem::factory()->create([
        'user_id' => $restaurant->id,
        'phone' => '01012345678',
        'phone_status' => 'active',
        'ai_file' => $oldFilePath,
    ]);

    $newFile = UploadedFile::fake()->create('new_whats_menu.json', 50, 'application/json');

    $response = $this->postJson('/api/user/whats/orders', [
        'whats_item_id' => $item->id,
        'package_id' => $package->id,
        'android_link' => 'https://play.google.com/store/apps/details?id=com.burger',
        'ios_link' => 'https://apps.apple.com/app/burger',
        'website_url' => 'https://burger.example.com',
        'ai_context' => 'سياق واتساب خاص بالمطعم تم تحديثه مع طلب الاشتراك',
        'ai_file' => $newFile,
    ]);

    $response->assertCreated();

    $freshItem = $item->fresh();
    expect($freshItem->android_link)->toBe('https://play.google.com/store/apps/details?id=com.burger');
    expect($freshItem->ios_link)->toBe('https://apps.apple.com/app/burger');
    expect($freshItem->website_url)->toBe('https://burger.example.com');
    expect($freshItem->ai_context)->toBe('سياق واتساب خاص بالمطعم تم تحديثه مع طلب الاشتراك');
    expect($freshItem->ai_file)->not->toBeNull();
    expect($freshItem->ai_file)->not->toBe($oldFilePath);

    // Verify old file was deleted and new file exists
    Storage::disk('public')->assertMissing($oldFilePath);
    Storage::disk('public')->assertExists($freshItem->ai_file);
});
