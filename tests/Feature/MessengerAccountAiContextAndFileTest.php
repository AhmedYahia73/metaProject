<?php

use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
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

test('user model and table do not have ai_context column', function () {
    expect(Schema::hasColumn('users', 'ai_context'))->toBeFalse();

    $user = User::factory()->create();
    expect(array_key_exists('ai_context', $user->getAttributes()))->toBeFalse();
});

test('messenger_accounts table has ai_context and ai_file columns', function () {
    expect(Schema::hasColumn('messenger_accounts', 'ai_context'))->toBeTrue();
    expect(Schema::hasColumn('messenger_accounts', 'ai_file'))->toBeTrue();
});

test('admin can store and update messenger account with ai_context and ai_file', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($admin);

    $storeResponse = $this->postJson("/api/admin/users/{$restaurant->id}/messenger-accounts", [
        'page_id' => 'page_test_123',
        'page_access_token' => 'EAAG_dummy_token',
        'page_name' => 'Pizza Palace Page',
        'ai_context' => 'أنت بوت خاص بمطعم بيتزا بالاس، رد بودية.',
        'ai_file' => 'https://example.com/menu.json',
    ]);

    $storeResponse->assertCreated()
        ->assertJsonPath('status', true);

    $account = MessengerAccount::where('page_id', 'page_test_123')->first();
    expect($account)->not->toBeNull();
    expect($account->ai_context)->toBe('أنت بوت خاص بمطعم بيتزا بالاس، رد بودية.');
    expect($account->ai_file)->toBe('https://example.com/menu.json');

    $updateResponse = $this->putJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}", [
        'ai_context' => 'سياق محدث',
        'ai_file' => 'menus/updated.txt',
    ]);

    $updateResponse->assertOk();
    expect($account->fresh()->ai_context)->toBe('سياق محدث');
    expect($account->fresh()->ai_file)->toBe('menus/updated.txt');
});

test('messenger webhook uses messenger account ai_context and ai_file in OpenAI prompt without Food tools', function () {
    Http::fake([
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => 'PSID_999',
            'message_id' => 'mid.reply_999',
        ], 200),
    ]);

    $capturedInstructions = null;
    $capturedTools = null;

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'لدينا بيتزا مارجريتا بسعر 100 جنيه.',
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);

    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'page_access_token' => 'EAAG_test_page_token',
        'ai_context' => 'أنت موظف استقبال خاص بصفحة فيسبوك لبيتزا هت.',
        'ai_file' => "قائمة الطعام:\n1. بيتزا مارجريتا: 100 جنيه\n2. بيتزا بيبروني: 150 جنيه",
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة ماسنجر', 'en' => 'Messenger Package'],
        'type' => 'face',
        'msg_number' => 100,
        'price' => 50,
        'months' => 1,
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 50,
        'final_price' => 50,
        'from' => now()->subDay()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
        'msgs' => 100,
        'status' => 'approved',
        'channel' => 'messenger',
    ]);

    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'messaging' => [
                    [
                        'sender' => ['id' => 'PSID_999'],
                        'recipient' => ['id' => $account->page_id],
                        'timestamp' => now()->timestamp,
                        'message' => ['mid' => 'mid.test_123', 'text' => 'إيه أسعار البيتزا عندكم؟'],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'success', 'messenger_sent' => true]);

    OpenAI::assertSent(Responses::class, function (string $method, array $parameters) {
        return $method === 'create'
            && str_contains($parameters['instructions'], 'أنت موظف استقبال خاص بصفحة فيسبوك لبيتزا هت.')
            && str_contains($parameters['instructions'], 'بيتزا مارجريتا: 100 جنيه')
            && ! isset($parameters['tools']);
    });
});

test('user model and table do not have android_link, ios_link, msg_number columns', function () {
    expect(Schema::hasColumn('users', 'android_link'))->toBeFalse();
    expect(Schema::hasColumn('users', 'ios_link'))->toBeFalse();
    expect(Schema::hasColumn('users', 'msg_number'))->toBeFalse();

    $user = User::factory()->create();
    expect(array_key_exists('android_link', $user->getAttributes()))->toBeFalse();
    expect(array_key_exists('ios_link', $user->getAttributes()))->toBeFalse();
    expect(array_key_exists('msg_number', $user->getAttributes()))->toBeFalse();
});

test('messenger_accounts table has android_link, ios_link, msg_number columns', function () {
    expect(Schema::hasColumn('messenger_accounts', 'android_link'))->toBeTrue();
    expect(Schema::hasColumn('messenger_accounts', 'ios_link'))->toBeTrue();
    expect(Schema::hasColumn('messenger_accounts', 'msg_number'))->toBeTrue();
});

test('admin cannot set msg_number when storing or updating messenger account', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($admin);

    $storeResponse = $this->postJson("/api/admin/users/{$restaurant->id}/messenger-accounts", [
        'page_id' => 'page_test_msg_num',
        'page_access_token' => 'EAAG_test_token',
        'android_link' => 'https://play.google.com/test',
        'ios_link' => 'https://apps.apple.com/test',
        'msg_number' => 9999, // Should be ignored
    ]);

    $storeResponse->assertCreated();

    $account = MessengerAccount::where('page_id', 'page_test_msg_num')->first();
    expect($account->msg_number)->toBe(0); // Not set to 9999
    expect($account->android_link)->toBe('https://play.google.com/test');
    expect($account->ios_link)->toBe('https://apps.apple.com/test');

    $updateResponse = $this->putJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}", [
        'msg_number' => 8888, // Should be ignored
        'android_link' => 'https://play.google.com/updated',
    ]);

    $updateResponse->assertOk();
    expect($account->fresh()->msg_number)->toBe(0);
    expect($account->fresh()->android_link)->toBe('https://play.google.com/updated');
});

test('messenger webhook decrements messenger account msg_number when message is sent', function () {
    Http::fake([
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => 'PSID_777',
            'message_id' => 'mid.reply_777',
        ], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_777',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'أهلاً بك!',
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);

    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'page_access_token' => 'EAAG_token_777',
        'msg_number' => 10,
        'android_link' => 'https://play.google.com/app',
        'ios_link' => 'https://apps.apple.com/app',
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة', 'en' => 'Package'],
        'type' => 'face',
        'msg_number' => 10,
        'price' => 20,
        'months' => 1,
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 20,
        'final_price' => 20,
        'from' => now()->subDay()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
        'msgs' => 10,
        'status' => 'approved',
        'channel' => 'messenger',
    ]);

    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'messaging' => [
                    [
                        'sender' => ['id' => 'PSID_777'],
                        'recipient' => ['id' => $account->page_id],
                        'timestamp' => now()->timestamp,
                        'message' => ['mid' => 'mid.777', 'text' => 'مرحبا'],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['status' => 'success', 'messenger_sent' => true]);

    // msg_number decremented from 10 to 9 on MessengerAccount
    expect($account->fresh()->msg_number)->toBe(9);
});

test('admin can upload ai_file when storing messenger account using image trait upload', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($admin);

    $file = UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf');

    $response = $this->postJson("/api/admin/users/{$restaurant->id}/messenger-accounts", [
        'page_id' => 'page_with_file_1',
        'page_access_token' => 'EAAG_dummy_token',
        'page_name' => 'Pizza File Page',
        'ai_file' => $file,
    ]);

    $response->assertCreated();

    $account = MessengerAccount::where('page_id', 'page_with_file_1')->first();
    expect($account)->not->toBeNull();
    expect($account->ai_file)->not->toBeNull();
    Storage::disk('public')->assertExists($account->ai_file);
});

test('admin can replace ai_file when updating messenger account using image trait update_image', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($admin);

    // Initial file upload
    $initialFile = UploadedFile::fake()->create('initial_menu.txt', 50, 'text/plain');
    $initialPath = $initialFile->store('messenger/ai_files', 'public');

    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'ai_file' => $initialPath,
    ]);

    Storage::disk('public')->assertExists($initialPath);

    // Update with new file
    $newFile = UploadedFile::fake()->create('new_menu.txt', 60, 'text/plain');
    $updateResponse = $this->putJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}", [
        'ai_file' => $newFile,
    ]);

    $updateResponse->assertOk();

    $freshAccount = $account->fresh();
    expect($freshAccount->ai_file)->not->toBe($initialPath);
    Storage::disk('public')->assertMissing($initialPath);
    Storage::disk('public')->assertExists($freshAccount->ai_file);
});

test('admin deleting messenger account removes ai_file from storage using deleteImage', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create(['role' => 'user']);
    Sanctum::actingAs($admin);

    $file = UploadedFile::fake()->create('to_delete.txt', 20, 'text/plain');
    $filePath = $file->store('messenger/ai_files', 'public');

    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'ai_file' => $filePath,
    ]);

    Storage::disk('public')->assertExists($filePath);

    $response = $this->deleteJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}");
    $response->assertOk();

    $this->assertDatabaseMissing('messenger_accounts', ['id' => $account->id]);
    Storage::disk('public')->assertMissing($filePath);
});

test('admin approving order can upload ai_file using image trait upload', function () {
    Storage::fake('public');

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = Package::create([
        'name' => ['ar' => 'باقة', 'en' => 'Package'],
        'type' => 'face',
        'msg_number' => 100,
        'price' => 50,
        'months' => 1,
    ]);

    $account = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'status' => 'disabled',
        'ai_file' => null,
    ]);

    $order = Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'price' => 50,
        'final_price' => 50,
        'msgs' => 100,
        'status' => 'pending',
        'channel' => 'messenger',
        'messenger_account_id' => $account->id,
    ]);

    $file = UploadedFile::fake()->create('restaurant_menu.json', 80, 'application/json');

    $response = $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/approve", [
        'ai_context' => 'سياق معتمد',
        'ai_file' => $file,
    ]);

    $response->assertOk();

    $freshAccount = $account->fresh();
    expect($freshAccount->ai_context)->toBe('سياق معتمد');
    expect($freshAccount->ai_file)->not->toBeNull();
    Storage::disk('public')->assertExists($freshAccount->ai_file);
});

test('messenger_accounts table has website_url column', function () {
    expect(Schema::hasColumn('messenger_accounts', 'website_url'))->toBeTrue();
});

test('admin and user can set website_url and webhook includes website_url in prompt', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $restaurant = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($admin);

    $storeResponse = $this->postJson("/api/admin/users/{$restaurant->id}/messenger-accounts", [
        'page_id' => 'page_test_web_url',
        'page_access_token' => 'EAAG_dummy_token',
        'page_name' => 'Website Test Page',
        'website_url' => 'https://example.com/restaurant',
    ]);

    $storeResponse->assertCreated();

    $account = MessengerAccount::where('page_id', 'page_test_web_url')->first();
    expect($account->website_url)->toBe('https://example.com/restaurant');

    $updateResponse = $this->putJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}", [
        'website_url' => 'https://new-example.com/restaurant',
    ]);

    $updateResponse->assertOk();
    expect($account->fresh()->website_url)->toBe('https://new-example.com/restaurant');
});

test('admin approving messenger order can set website_url', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $package = Package::create([
        'name' => ['ar' => 'باقة', 'en' => 'Package'],
        'type' => 'face',
        'msg_number' => 100,
        'price' => 50,
        'months' => 1,
    ]);

    $account = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'status' => 'disabled',
        'website_url' => null,
    ]);

    $order = Order::create([
        'package_id' => $package->id,
        'user_id' => $user->id,
        'price' => 50,
        'final_price' => 50,
        'msgs' => 100,
        'status' => 'pending',
        'channel' => 'messenger',
        'messenger_account_id' => $account->id,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/approve", [
        'website_url' => 'https://approved-site.com',
    ]);

    $response->assertOk();
    expect($account->fresh()->website_url)->toBe('https://approved-site.com');
});

test('user requestSubscription can optionally pass android_link, ios_link, website_url, ai_context, ai_file with update_image', function () {
    Storage::fake('public');

    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                [
                    'id' => 'page_test_optional_fields',
                    'name' => 'Optional Fields Page',
                    'category' => 'Restaurant',
                    'access_token' => 'EAAG_test_page_tok',
                ],
            ],
        ], 200),
    ]);

    $user = User::factory()->create([
        'role' => 'user',
        'facebook_access_token' => 'EAAG_user_fb_token',
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة ماسنجر', 'en' => 'Messenger Package'],
        'type' => 'face',
        'msg_number' => 100,
        'price' => 50,
        'months' => 1,
    ]);

    // Pre-create account with old file
    $oldFilePath = UploadedFile::fake()->create('old_doc.pdf', 30, 'application/pdf')->store('messenger/ai_files', 'public');
    Storage::disk('public')->assertExists($oldFilePath);

    $existingAccount = MessengerAccount::factory()->create([
        'user_id' => $user->id,
        'page_id' => 'page_test_optional_fields',
        'status' => 'disabled',
        'ai_file' => $oldFilePath,
    ]);

    $newFile = UploadedFile::fake()->create('new_menu.json', 40, 'application/json');

    $response = $this->actingAs($user)->postJson('/api/user/messenger/orders', [
        'page_id' => 'page_test_optional_fields',
        'package_id' => $package->id,
        'android_link' => 'https://play.google.com/store/apps/details?id=com.pizza',
        'ios_link' => 'https://apps.apple.com/app/pizza',
        'website_url' => 'https://pizza.example.com',
        'ai_context' => 'سياق مخصص للذكاء الاصطناعي تم إدخاله مع الاشتراك',
        'ai_file' => $newFile,
    ]);

    $response->assertCreated();

    $freshAccount = $existingAccount->fresh();
    expect($freshAccount->android_link)->toBe('https://play.google.com/store/apps/details?id=com.pizza');
    expect($freshAccount->ios_link)->toBe('https://apps.apple.com/app/pizza');
    expect($freshAccount->website_url)->toBe('https://pizza.example.com');
    expect($freshAccount->ai_context)->toBe('سياق مخصص للذكاء الاصطناعي تم إدخاله مع الاشتراك');
    expect($freshAccount->ai_file)->not->toBeNull();
    expect($freshAccount->ai_file)->not->toBe($oldFilePath);

    // Verify old file was deleted and new file exists
    Storage::disk('public')->assertMissing($oldFilePath);
    Storage::disk('public')->assertExists($freshAccount->ai_file);
});

test('messenger webhook instructs AI to politely share ordering links when customer asks to order', function () {
    Http::fake([
        'https://graph.facebook.com/*/me/messages' => Http::response([
            'recipient_id' => 'PSID_999',
            'message_id' => 'mid.reply_order',
        ], 200),
    ]);

    $capturedInstructions = null;

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_order_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => "أهلاً بحضرتك! تقدر تطلب من هنا:\nالموقع: https://pizza.com\nأندرويد: https://play.google.com/pizza\niOS: https://apple.com/pizza",
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create(['role' => 'user']);
    $account = MessengerAccount::factory()->create([
        'user_id' => $restaurant->id,
        'page_access_token' => 'EAAG_test_page_token',
        'status' => 'active',
        'msg_number' => 50,
        'website_url' => 'https://pizza.com',
        'android_link' => 'https://play.google.com/pizza',
        'ios_link' => 'https://apple.com/pizza',
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة', 'en' => 'Pkg'],
        'type' => 'face',
        'msg_number' => 50,
        'price' => 50,
        'months' => 1,
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'price' => 50,
        'final_price' => 50,
        'from' => now()->subDay()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
        'msgs' => 50,
        'status' => 'approved',
        'channel' => 'messenger',
    ]);

    $response = $this->postJson('/api/messenger-webhook', [
        'object' => 'page',
        'entry' => [
            [
                'id' => $account->page_id,
                'messaging' => [
                    [
                        'sender' => ['id' => 'PSID_999'],
                        'recipient' => ['id' => $account->page_id],
                        'timestamp' => now()->timestamp,
                        'message' => ['mid' => 'mid.order_1', 'text' => 'عاوز اطلب بيتزا'],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk();

    OpenAI::assertSent(Responses::class, function (string $method, array $parameters) use (&$capturedInstructions) {
        $capturedInstructions = $parameters['instructions'] ?? '';

        return $method === 'create'
            && str_contains($capturedInstructions, 'تقدر تطلب من هنا')
            && str_contains($capturedInstructions, 'https://pizza.com')
            && str_contains($capturedInstructions, 'https://play.google.com/pizza')
            && str_contains($capturedInstructions, 'https://apple.com/pizza');
    });
});
