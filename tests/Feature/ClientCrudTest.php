<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'user']);
    $this->otherUser = User::factory()->create(['role' => 'user']);
});

test('unauthenticated user cannot access clients endpoints', function () {
    $this->getJson('/api/user/clients')->assertUnauthorized();
});

test('user can list only their own clients', function () {
    Client::factory()->count(3)->create(['user_id' => $this->user->id]);
    Client::factory()->count(2)->create(['user_id' => $this->otherUser->id]);

    Sanctum::actingAs($this->user);

    $response = $this->getJson('/api/user/clients');

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonCount(3, 'data.data');
});

test('user can search their clients by name or whatsapp number', function () {
    Client::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Ahmed Mohamed',
        'whats_number' => '201011111111',
    ]);
    Client::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Sara Ali',
        'whats_number' => '201022222222',
    ]);

    Sanctum::actingAs($this->user);

    $response = $this->getJson('/api/user/clients?search=Ahmed');
    $response->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.name', 'Ahmed Mohamed');

    $responseNumber = $this->getJson('/api/user/clients?search=201022222222');
    $responseNumber->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.name', 'Sara Ali');
});

test('user can create a client scoped to their account', function () {
    Sanctum::actingAs($this->user);

    $payload = [
        'name' => 'Mahmoud Hassan',
        'whats_number' => '201099998888',
    ];

    $response = $this->postJson('/api/user/clients', $payload);

    $response->assertCreated()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'Client created successfully.')
        ->assertJsonPath('data.name', 'Mahmoud Hassan')
        ->assertJsonPath('data.whats_number', '201099998888')
        ->assertJsonPath('data.user_id', $this->user->id);

    $this->assertDatabaseHas('clients', [
        'user_id' => $this->user->id,
        'name' => 'Mahmoud Hassan',
        'whats_number' => '201099998888',
    ]);
});

test('validation fails when required fields are missing on store', function () {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/user/clients', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'whats_number']);
});

test('user can view their own client', function () {
    $client = Client::factory()->create(['user_id' => $this->user->id]);

    Sanctum::actingAs($this->user);

    $response = $this->getJson("/api/user/clients/{$client->id}");

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.id', $client->id)
        ->assertJsonPath('data.name', $client->name);
});

test('user cannot view another users client', function () {
    $otherClient = Client::factory()->create(['user_id' => $this->otherUser->id]);

    Sanctum::actingAs($this->user);

    $response = $this->getJson("/api/user/clients/{$otherClient->id}");

    $response->assertForbidden()
        ->assertJsonPath('status', false)
        ->assertJsonPath('message', 'Unauthorized.');
});

test('user can update their own client', function () {
    $client = Client::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Old Name',
        'whats_number' => '201000000000',
    ]);

    Sanctum::actingAs($this->user);

    $response = $this->putJson("/api/user/clients/{$client->id}", [
        'name' => 'New Name',
        'whats_number' => '201111111111',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'Client updated successfully.')
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.whats_number', '201111111111');

    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'name' => 'New Name',
        'whats_number' => '201111111111',
    ]);
});

test('user cannot update another users client', function () {
    $otherClient = Client::factory()->create([
        'user_id' => $this->otherUser->id,
        'name' => 'Other Client',
        'whats_number' => '201000000000',
    ]);

    Sanctum::actingAs($this->user);

    $response = $this->putJson("/api/user/clients/{$otherClient->id}", [
        'name' => 'Hacked Name',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('status', false);

    $this->assertDatabaseHas('clients', [
        'id' => $otherClient->id,
        'name' => 'Other Client',
    ]);
});

test('user can delete their own client', function () {
    $client = Client::factory()->create(['user_id' => $this->user->id]);

    Sanctum::actingAs($this->user);

    $response = $this->deleteJson("/api/user/clients/{$client->id}");

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'Client deleted successfully.');

    $this->assertDatabaseMissing('clients', [
        'id' => $client->id,
    ]);
});

test('user cannot delete another users client', function () {
    $otherClient = Client::factory()->create(['user_id' => $this->otherUser->id]);

    Sanctum::actingAs($this->user);

    $response = $this->deleteJson("/api/user/clients/{$otherClient->id}");

    $response->assertForbidden()
        ->assertJsonPath('status', false);

    $this->assertDatabaseHas('clients', [
        'id' => $otherClient->id,
    ]);
});
