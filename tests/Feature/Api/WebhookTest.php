<?php

use App\Models\Chat;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;

uses(RefreshDatabase::class);

test('webhook handles meta GET verification challenge', function () {
    config(['services.meta.verify_token' => 'test']);
    $response = $this->get('/api/web-hook?hub_mode=subscribe&hub_verify_token=test&hub_challenge=1158201444');

    $response->assertOk();
    expect($response->getContent())->toBe('1158201444');
});

test('webhook returns ignored when phone_number_id is missing', function () {
    $response = $this->postJson('/api/web-hook', [
        'object' => 'whatsapp_business_account',
        'entry' => [],
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'ignored']);
});

test('webhook returns restaurant_not_found when phone_number_id is unknown', function () {
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => '123456789',
                'changes' => [
                    [
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'phone_number_id' => 'unknown_id',
                            ],
                            'messages' => [
                                [
                                    'from' => '201012345678',
                                    'text' => ['body' => 'Hello'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/web-hook', $payload);

    $response->assertOk();
    $response->assertJson(['status' => 'restaurant_not_found']);
});

test('webhook returns limit_exceeded when restaurant has no active subscription', function () {
    $restaurant = User::factory()->create([
        'role' => 'user',
    ]);

    WhatsItem::factory()->create([
        'user_id' => $restaurant->id,
        'phone_number_id' => '999888777',
        'access_token' => 'meta_token_123',
        'msg_number' => 0,
    ]);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => '123456789',
                'changes' => [
                    [
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'phone_number_id' => '999888777',
                            ],
                            'contacts' => [
                                [
                                    'profile' => ['name' => 'Ahmed'],
                                ],
                            ],
                            'messages' => [
                                [
                                    'from' => '201012345678',
                                    'text' => ['body' => 'Hello'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/web-hook', $payload);

    $response->assertOk();
    $response->assertJson(['status' => 'limit_exceeded']);
});

test('webhook processes incoming message, generates AI reply, and sends whatsapp response', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '201012345678', 'wa_id' => '201012345678']],
            'messages' => [['id' => 'wamid.test_reply_123']],
        ], 200),
    ]);

    OpenAI::fake([
        CreateResponse::fake([
            'output' => [
                0 => [
                    'type' => 'message',
                    'id' => 'msg_123',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'أهلاً بك في مطعمنا! كيف أقدر أساعدك؟',
                            'annotations' => [],
                        ],
                    ],
                ],
                1 => [
                    'content' => [
                        ['text' => ''],
                    ],
                ],
            ],
        ]),
    ]);

    $restaurant = User::factory()->create([
        'role' => 'user',
    ]);

    $whatsItem = WhatsItem::factory()->create([
        'user_id' => $restaurant->id,
        'phone_number_id' => '999888777',
        'access_token' => 'EAAG...fake_token',
        'android_link' => 'https://play.google.com/store/apps/details?id=com.keeto',
        'ios_link' => 'https://apps.apple.com/app/keeto',
        'msg_number' => 500,
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة تجريبية', 'en' => 'Test Package'],
        'msg_number' => 500,
        'price' => 100,
        'months' => 1,
    ]);

    Order::create([
        'package_id' => $package->id,
        'user_id' => $restaurant->id,
        'whats_item_id' => $whatsItem->id,
        'total_discount' => 0,
        'total_tax' => 0,
        'price' => 100,
        'final_price' => 100,
        'from' => now()->subDay()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
        'msgs' => 500,
    ]);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => '123456789',
                'changes' => [
                    [
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'display_phone_number' => '15551234567',
                                'phone_number_id' => '999888777',
                            ],
                            'contacts' => [
                                [
                                    'profile' => [
                                        'name' => 'Ahmed Yahia',
                                    ],
                                    'wa_id' => '201012345678',
                                ],
                            ],
                            'messages' => [
                                [
                                    'from' => '201012345678',
                                    'id' => 'wamid.HBgLMjAxMDEyMzQ1Njc4FQIAEhgg...',
                                    'timestamp' => '1710000000',
                                    'text' => [
                                        'body' => 'السلام عليكم، عاوز اعرف المنيو',
                                    ],
                                    'type' => 'text',
                                ],
                            ],
                        ],
                        'field' => 'messages',
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/web-hook', $payload);

    $response->assertOk();
    $response->assertJson(['status' => 'success']);

    // Check customer message was saved
    $customerChat = Chat::where('user_id', $restaurant->id)
        ->where('phone', '201012345678')
        ->where('is_admin', false)
        ->first();

    expect($customerChat)->not->toBeNull();
    expect($customerChat->message)->toBe('السلام عليكم، عاوز اعرف المنيو');
    expect($customerChat->name)->toBe('Ahmed Yahia');

    // Check AI reply was saved
    $replyChat = Chat::where('user_id', $restaurant->id)
        ->where('phone', '201012345678')
        ->where('is_admin', true)
        ->first();

    expect($replyChat)->not->toBeNull();
    expect($replyChat->message)->toBe('أهلاً بك في مطعمنا! كيف أقدر أساعدك؟');

    // Check message counter was recorded
    expect(MsgSend::where('user_id', $restaurant->id)->count())->toBe(1);

    // Verify WhatsApp Graph API was called
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '999888777/messages')
            && $request['to'] === '201012345678'
            && $request['text']['body'] === 'أهلاً بك في مطعمنا! كيف أقدر أساعدك؟';
    });
});
