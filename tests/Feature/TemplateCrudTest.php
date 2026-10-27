<?php

use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('regular users cannot manage templates', function () {
    $user = User::factory()->create([
        'role' => 'user',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/admin/templates');

    $response->assertForbidden();
});
