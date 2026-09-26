<?php

use App\Models\Package;
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

test('admin can list packages and optionally filter by type', function () {
    Package::factory()->create(['name' => ['en' => 'WhatsApp Package', 'ar' => 'باقة واتساب'], 'type' => 'whats']);
    Package::factory()->create(['name' => ['en' => 'Facebook Package', 'ar' => 'باقة فيسبوك'], 'type' => 'face']);
    Package::factory()->create(['name' => ['en' => 'All Package', 'ar' => 'باقة شاملة'], 'type' => 'all']);

    $response = $this->getJson('/api/admin/packages');
    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonCount(3, 'data');

    $faceResponse = $this->getJson('/api/admin/packages?type=face');
    $faceResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'face');

    $whatsResponse = $this->getJson('/api/admin/packages?type=whats');
    $whatsResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'whats');
});

test('admin can create package with valid enum type', function (string $type) {
    $payload = [
        'name' => [
            'en' => ucfirst($type).' Package',
            'ar' => 'باقة '.$type,
        ],
        'type' => $type,
        'msg_number' => 2000,
        'price' => 150.00,
        'months' => 2,
    ];

    $response = $this->postJson('/api/admin/packages', $payload);

    $response->assertCreated()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.type', $type)
        ->assertJsonPath('data.msg_number', 2000);

    $this->assertDatabaseHas('packages', [
        'type' => $type,
        'msg_number' => 2000,
    ]);
})->with(['whats', 'face', 'all']);

test('package creation fails with invalid or missing type', function () {
    $basePayload = [
        'name' => ['en' => 'Test', 'ar' => 'تجربة'],
        'msg_number' => 100,
        'price' => 50,
        'months' => 1,
    ];

    // Missing type
    $this->postJson('/api/admin/packages', $basePayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);

    // Invalid type
    $this->postJson('/api/admin/packages', array_merge($basePayload, ['type' => 'invalid_type']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('admin can view a single package', function () {
    $package = Package::factory()->create([
        'type' => 'face',
    ]);

    $response = $this->getJson("/api/admin/packages/{$package->id}");

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.id', $package->id)
        ->assertJsonPath('data.type', 'face');
});

test('admin can update package type', function () {
    $package = Package::factory()->create([
        'type' => 'face',
    ]);

    $response = $this->putJson("/api/admin/packages/{$package->id}", [
        'type' => 'whats',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.type', 'whats');

    $this->assertDatabaseHas('packages', [
        'id' => $package->id,
        'type' => 'whats',
    ]);
});

test('admin update fails when type is invalid', function () {
    $package = Package::factory()->create();

    $response = $this->putJson("/api/admin/packages/{$package->id}", [
        'type' => 'unsupported',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('admin can delete a package', function () {
    $package = Package::factory()->create();

    $response = $this->deleteJson("/api/admin/packages/{$package->id}");

    $response->assertOk()
        ->assertJsonPath('status', true);

    $this->assertDatabaseMissing('packages', [
        'id' => $package->id,
    ]);
});
