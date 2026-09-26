<?php

use Database\Seeders\WebhookTestSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasTable('food')) {
        Schema::create('food', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->text('description_ar')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->string('discount_type', 50)->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->boolean('is_out_of_stock')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    $this->seed(WebhookTestSeeder::class);
});

test('webhook verifies challenge successfully', function () {
    $verifyToken = config('services.meta.verify_token');

    $response = $this->get('/api/web-hook?hub_mode=subscribe&hub_verify_token='.$verifyToken.'&hub_challenge=1158201444');

    $response->assertStatus(200);
    expect($response->getContent())->toBe('1158201444');
});

test('webhook fails on invalid token', function () {
    $response = $this->get('/api/web-hook?hub_mode=subscribe&hub_verify_token=wrong_token&hub_challenge=1158201444');

    $response->assertStatus(403);
});

test('webhook receives message and processes auto reply', function () {
    $phoneNumberId = config('services.meta.phone_number_id') ?: '1296872370175605';

    // Fake WhatsApp Cloud API send message endpoint
    Http::fake([
        "https://graph.facebook.com/*/{$phoneNumberId}/messages" => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '201206610346', 'wa_id' => '201206610346']],
            'messages' => [['id' => 'wamid.fake_id_123', 'message_status' => 'accepted']],
        ], 200),
    ]);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => '3732693236881976',
                'changes' => [
                    [
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'display_phone_number' => '15552039785',
                                'phone_number_id' => $phoneNumberId,
                            ],
                            'contacts' => [
                                [
                                    'profile' => ['name' => 'Ahmed'],
                                    'wa_id' => '201206610346',
                                ],
                            ],
                            'messages' => [
                                [
                                    'from' => '201206610346',
                                    'id' => 'wamid.test_incoming_msg_001',
                                    'timestamp' => '1726915000',
                                    'text' => [
                                        'body' => 'السلام عليكم، عاوز أعرف أسعار البرجر عندكم؟',
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

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'success',
        'whatsapp_sent' => true,
    ]);

    $this->assertDatabaseHas('chats', [
        'phone' => '201206610346',
        'is_admin' => false,
    ]);

    $this->assertDatabaseHas('chats', [
        'phone' => '201206610346',
        'is_admin' => true,
    ]);
});
