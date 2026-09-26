<?php

use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
