<?php

use App\Models\Paymob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@test.com',
        'password' => Hash::make('password123'),
        'role' => 'admin',
    ]);

    $this->regularUser = User::create([
        'name' => 'Regular User',
        'phone' => '01011112222',
        'restuarant_name' => 'Pizza House',
        'password' => Hash::make('password123'),
        'role' => 'user',
    ]);
});

test('unauthenticated users cannot view or update paymob settings', function () {
    $this->getJson('/api/admin/paymob')->assertUnauthorized();
    $this->postJson('/api/admin/paymob', [])->assertUnauthorized();
    $this->putJson('/api/admin/paymob', [])->assertUnauthorized();
});

test('non-admin users are forbidden from viewing or updating paymob settings', function () {
    Sanctum::actingAs($this->regularUser);

    $this->getJson('/api/admin/paymob')->assertForbidden();
    $this->postJson('/api/admin/paymob', [])->assertForbidden();
    $this->putJson('/api/admin/paymob', [])->assertForbidden();
});

test('admin can view paymob settings when empty and returns null data', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/admin/paymob');

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'data' => null,
        ]);
});

test('admin can create paymob settings with logo upload when empty', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->admin);

    $logo = UploadedFile::fake()->image('paymob.png', 200, 200);

    $response = $this->postJson('/api/admin/paymob', [
        'title' => 'Paymob Online Payment',
        'logo' => $logo,
        'type' => 'test',
        'callback' => 'https://example.com/api/paymob/callback',
        'api_key' => 'ZXCVB123456APIKEY',
        'iframe_id' => '123456',
        'integration_id' => '789012',
        'Hmac' => 'TEST_HMAC_SECRET_999',
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'status' => true,
            'message' => 'Paymob settings created successfully.',
            'data' => [
                'title' => 'Paymob Online Payment',
                'type' => 'test',
                'callback' => 'https://example.com/api/paymob/callback',
                'api_key' => 'ZXCVB123456APIKEY',
                'iframe_id' => '123456',
                'integration_id' => '789012',
                'Hmac' => 'TEST_HMAC_SECRET_999',
            ],
        ]);

    $data = $response->json('data');
    expect($data['logo'])->toContain('/storage/paymob/');
    expect($data['logo_url'])->toBe($data['logo']);

    $paymob = Paymob::first();
    expect($paymob)->not->toBeNull();
    expect($paymob->title)->toBe('Paymob Online Payment');

    $rawLogo = $paymob->getRawOriginal('logo');
    expect($rawLogo)->toStartWith('paymob/');
    Storage::disk('public')->assertExists($rawLogo);
});

test('admin can view existing paymob settings and receives full logo link', function () {
    Sanctum::actingAs($this->admin);

    $paymob = Paymob::create([
        'title' => 'Paymob Gateway',
        'logo' => 'paymob/gateway_logo.png',
        'type' => 'live',
        'callback' => 'https://example.com/paymob/callback',
        'api_key' => 'LIVE_API_KEY_999',
        'iframe_id' => '88888',
        'integration_id' => '77777',
        'Hmac' => 'LIVE_HMAC_123',
    ]);

    $response = $this->getJson('/api/admin/paymob');

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'id' => $paymob->id,
                'title' => 'Paymob Gateway',
                'type' => 'live',
                'callback' => 'https://example.com/paymob/callback',
                'api_key' => 'LIVE_API_KEY_999',
                'iframe_id' => '88888',
                'integration_id' => '77777',
                'Hmac' => 'LIVE_HMAC_123',
            ],
        ]);

    expect($response->json('data.logo'))->toContain('/storage/paymob/gateway_logo.png');
    expect($response->json('data.logo_url'))->toContain('/storage/paymob/gateway_logo.png');
});

test('admin can update existing paymob settings and replaces logo via update_image', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->admin);

    // Initial logo in storage
    $oldFile = UploadedFile::fake()->image('old_logo.png');
    $oldPath = $oldFile->store('paymob', 'public');
    Storage::disk('public')->assertExists($oldPath);

    $paymob = Paymob::create([
        'title' => 'Initial Title',
        'logo' => $oldPath,
        'type' => 'test',
        'callback' => 'https://example.com/old/callback',
        'api_key' => 'OLD_KEY',
        'iframe_id' => '111',
        'integration_id' => '222',
        'Hmac' => 'OLD_HMAC',
    ]);

    $newLogo = UploadedFile::fake()->image('new_logo.png');

    $response = $this->postJson('/api/admin/paymob', [
        'title' => 'Updated Title',
        'logo' => $newLogo,
        'type' => 'live',
        'callback' => 'https://example.com/new/callback',
        'api_key' => 'NEW_KEY',
        'iframe_id' => '333',
        'integration_id' => '444',
        'Hmac' => 'NEW_HMAC',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'message' => 'Paymob settings updated successfully.',
            'data' => [
                'id' => $paymob->id,
                'title' => 'Updated Title',
                'type' => 'live',
                'callback' => 'https://example.com/new/callback',
                'api_key' => 'NEW_KEY',
                'iframe_id' => '333',
                'integration_id' => '444',
                'Hmac' => 'NEW_HMAC',
            ],
        ]);

    $paymob->refresh();
    $newRawLogo = $paymob->getRawOriginal('logo');

    // Old logo must be deleted by update_image
    Storage::disk('public')->assertMissing($oldPath);
    // New logo must exist
    Storage::disk('public')->assertExists($newRawLogo);
    expect($newRawLogo)->not->toBe($oldPath);
    expect($response->json('data.logo'))->toContain('/storage/paymob/');
});

test('admin can update paymob settings without changing logo', function () {
    Sanctum::actingAs($this->admin);

    $paymob = Paymob::create([
        'title' => 'Original Title',
        'logo' => 'paymob/keep_me.png',
        'type' => 'test',
        'callback' => 'https://example.com/callback',
        'api_key' => 'KEY123',
        'iframe_id' => '111',
        'integration_id' => '222',
        'Hmac' => 'HMAC123',
    ]);

    $response = $this->putJson('/api/admin/paymob', [
        'title' => 'Only Title Changed',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'data' => [
                'id' => $paymob->id,
                'title' => 'Only Title Changed',
            ],
        ]);

    $paymob->refresh();
    expect($paymob->getRawOriginal('logo'))->toBe('paymob/keep_me.png');
    expect($response->json('data.logo'))->toContain('/storage/paymob/keep_me.png');
});

test('admin can send hmac in lowercase or uppercase and normalizes properly', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->admin);

    $logo = UploadedFile::fake()->image('logo.png');

    $response = $this->postJson('/api/admin/paymob', [
        'title' => 'Paymob Lowercase HMAC',
        'logo' => $logo,
        'type' => 'test',
        'callback' => 'https://example.com/callback',
        'api_key' => 'KEY',
        'iframe_id' => '1',
        'integration_id' => '2',
        'hmac' => 'LOWERCASE_HMAC_VALUE',
    ]);

    $response->assertStatus(201);
    expect($response->json('data.Hmac'))->toBe('LOWERCASE_HMAC_VALUE');

    $paymob = Paymob::first();
    expect($paymob->Hmac)->toBe('LOWERCASE_HMAC_VALUE');
});
