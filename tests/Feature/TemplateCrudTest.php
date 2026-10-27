<?php

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\Template;
use App\Models\TikTokAccount;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => 'admin',
    ]);

    Sanctum::actingAs($this->admin);
});

test('admin can list templates', function () {
    Template::factory()->count(3)->create();

    $response = $this->getJson('/api/admin/templates');

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonCount(3, 'data');
});

test('admin can create template with valid groups schema', function () {
    $payload = [
        'groups' => [
            [
                'name' => 'Customer Details',
                'data' => [
                    [
                        [
                            'lable' => 'Full Name',
                            'value' => 'text',
                        ],
                        [
                            'lable' => 'Age',
                            'value' => 'numeric',
                        ],
                    ],
                    [
                        [
                            'lable' => 'Notes',
                            'value' => 'textarea',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/admin/templates', $payload);

    $response->assertCreated()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'Template created successfully.')
        ->assertJsonPath('data.request_template.groups.0.name', 'Customer Details')
        ->assertJsonPath('data.request_template.groups.0.data.0.0.lable', 'Full Name')
        ->assertJsonPath('data.request_template.groups.0.data.0.0.value', 'text')
        ->assertJsonPath('data.request_template.groups.0.data.0.1.value', 'numeric')
        ->assertJsonPath('data.request_template.groups.0.data.1.0.value', 'textarea');

    $this->assertDatabaseHas('templates', [
        'id' => $response->json('data.id'),
    ]);
});

test('template validation fails if groups is missing or empty', function () {
    $response = $this->postJson('/api/admin/templates', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['groups']);

    $responseEmpty = $this->postJson('/api/admin/templates', ['groups' => []]);

    $responseEmpty->assertUnprocessable()
        ->assertJsonValidationErrors(['groups']);
});

test('template validation fails if group name is missing', function () {
    $payload = [
        'groups' => [
            [
                'data' => [
                    [
                        [
                            'lable' => 'Test',
                            'value' => 'text',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/admin/templates', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['groups.0.name']);
});

test('template validation fails if lable or value is missing or invalid', function () {
    $payload = [
        'groups' => [
            [
                'name' => 'Group 1',
                'data' => [
                    [
                        [
                            'value' => 'invalid_input_type',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/admin/templates', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'groups.0.data.0.0.lable',
            'groups.0.data.0.0.value',
        ]);
});

test('admin can view single template', function () {
    $template = Template::factory()->create();

    $response = $this->getJson("/api/admin/templates/{$template->id}");

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.id', $template->id);
});

test('admin can update a template', function () {
    $template = Template::factory()->create();

    $payload = [
        'groups' => [
            [
                'name' => 'Updated Group',
                'data' => [
                    [
                        [
                            'lable' => 'Updated Field',
                            'value' => 'select',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->putJson("/api/admin/templates/{$template->id}", $payload);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'Template updated successfully.')
        ->assertJsonPath('data.request_template.groups.0.name', 'Updated Group')
        ->assertJsonPath('data.request_template.groups.0.data.0.0.value', 'select');
});

test('admin can delete a template', function () {
    $template = Template::factory()->create();

    $response = $this->deleteJson("/api/admin/templates/{$template->id}");

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'Template deleted successfully.');

    $this->assertDatabaseMissing('templates', [
        'id' => $template->id,
    ]);
});

test('admin can list accounts with only id and identifier', function () {
    WhatsItem::factory()->create(['phone' => '+201011111111']);
    InstagramItem::factory()->create(['username' => 'ig_user_1']);
    MessengerAccount::factory()->create(['page_name' => 'FB Page 1']);
    TelegramAccount::factory()->create(['bot_username' => 'tg_bot_1']);
    TikTokAccount::factory()->create(['username' => 'tiktok_user_1']);

    $response = $this->getJson('/api/admin/templates/accounts');

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonStructure([
            'data' => [
                'whats_items' => [['id', 'phone']],
                'instgram_items' => [['id', 'username']],
                'messenger_accounts' => [['id', 'page_name']],
                'telegram_accounts' => [['id', 'bot_username']],
                'tiktok_accounts' => [['id', 'username']],
            ],
        ]);

    $filteredResponse = $this->getJson('/api/admin/templates/accounts?type=whats_item');
    $filteredResponse->assertOk()
        ->assertJsonStructure(['data' => ['whats_items']]);
});

test('admin can generate markdown AI file and assign it to whats item', function () {
    Storage::fake('public');

    $whatsItem = WhatsItem::factory()->create([
        'ai_file' => null,
    ]);

    $payload = [
        'whats_item_id' => $whatsItem->id,
        'groups' => [
            [
                'name' => 'Menu Items',
                'data' => [
                    [
                        ['lable' => 'Meal Name', 'value' => 'Beef Burger'],
                        ['lable' => 'Price', 'value' => 150],
                        ['lable' => 'Available', 'value' => 'Yes'],
                    ],
                    [
                        ['lable' => 'Meal Name', 'value' => 'Pepperoni Pizza'],
                        ['lable' => 'Price', 'value' => 180],
                        ['lable' => 'Available', 'value' => 'Yes'],
                    ],
                ],
            ],
            [
                'name' => 'Beverages',
                'data' => [
                    [
                        ['lable' => 'Item', 'value' => 'Cola'],
                        ['lable' => 'Price', 'value' => 25],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/admin/templates/generate-ai-file', $payload);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.account_type', 'whats_item')
        ->assertJsonPath('data.account_id', $whatsItem->id);

    $filePath = $response->json('data.ai_file');
    $fileUrl = $response->json('data.file_url');

    expect($filePath)->not->toBeNull();
    expect($fileUrl)->toContain($filePath);

    Storage::disk('public')->assertExists($filePath);

    $savedContent = Storage::disk('public')->get($filePath);
    expect($savedContent)
        ->toContain('# Menu Items')
        ->toContain('| Meal Name | Price | Available |')
        ->toContain('| --- | --- | --- |')
        ->toContain('| Beef Burger | 150 | Yes |')
        ->toContain('| Pepperoni Pizza | 180 | Yes |')
        ->toContain('# Beverages')
        ->toContain('| Item | Price |')
        ->toContain('| Cola | 25 |');

    $whatsItem->refresh();
    expect($whatsItem->ai_file)->toBe($filePath);
});

test('generate-ai-file fails if no account ID or multiple account IDs provided', function () {
    $payload = [
        'groups' => [
            [
                'name' => 'Test',
                'data' => [
                    [
                        ['lable' => 'Field', 'value' => 'Value'],
                    ],
                ],
            ],
        ],
    ];

    // Missing account id
    $responseNone = $this->postJson('/api/admin/templates/generate-ai-file', $payload);
    $responseNone->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id']);

    // Multiple account ids
    $whatsItem = WhatsItem::factory()->create();
    $messenger = MessengerAccount::factory()->create();

    $payload['whats_item_id'] = $whatsItem->id;
    $payload['messenger_account_id'] = $messenger->id;

    $responseMultiple = $this->postJson('/api/admin/templates/generate-ai-file', $payload);
    $responseMultiple->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id']);
});

test('regular users cannot manage templates', function () {
    $user = User::factory()->create([
        'role' => 'user',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/admin/templates');

    $response->assertForbidden();
});
