<?php

use App\Models\MessengerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function adminUser(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function restaurantUser(): User
{
    return User::factory()->create(['role' => 'user']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Index
// ─────────────────────────────────────────────────────────────────────────────

test('admin can list messenger accounts for a user', function () {
    $admin = adminUser();
    $restaurant = restaurantUser();

    MessengerAccount::factory()->count(3)->create(['user_id' => $restaurant->id]);

    $response = $this->actingAs($admin)
        ->getJson("/api/admin/users/{$restaurant->id}/messenger-accounts");

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['status', 'data']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Store
// ─────────────────────────────────────────────────────────────────────────────

test('admin can add a messenger page to a user and gets verify_token', function () {
    $admin = adminUser();
    $restaurant = restaurantUser();

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/users/{$restaurant->id}/messenger-accounts", [
            'page_id' => '123456789012345',
            'page_access_token' => 'EAAGfakeTokenXYZ',
            'page_name' => 'My Restaurant Page',
        ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'status',
            'message',
            'data',
            'setup' => ['webhook_url', 'verify_token', 'note'],
        ]);

    $this->assertDatabaseHas('messenger_accounts', [
        'user_id' => $restaurant->id,
        'page_id' => '123456789012345',
        'status' => 'active',
    ]);

    // verify_token must be a valid UUID
    $verifyToken = $response->json('setup.verify_token');
    expect($verifyToken)->toMatch('/^[0-9a-f\-]{36}$/');
});

test('store fails with duplicate page_id', function () {
    $admin = adminUser();
    $restaurant = restaurantUser();

    MessengerAccount::factory()->create(['page_id' => 'EXISTING_PAGE_ID']);

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/users/{$restaurant->id}/messenger-accounts", [
            'page_id' => 'EXISTING_PAGE_ID',
            'page_access_token' => 'EAAGsomeToken',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['page_id']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Show
// ─────────────────────────────────────────────────────────────────────────────

test('admin can show a specific messenger account', function () {
    $admin = adminUser();
    $restaurant = restaurantUser();
    $account = MessengerAccount::factory()->create(['user_id' => $restaurant->id]);

    $response = $this->actingAs($admin)
        ->getJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}");

    $response->assertOk()
        ->assertJsonPath('data.page_id', $account->page_id);
});

test('show returns 404 when account belongs to different user', function () {
    $admin = adminUser();
    $restaurant1 = restaurantUser();
    $restaurant2 = restaurantUser();
    $account = MessengerAccount::factory()->create(['user_id' => $restaurant2->id]);

    $response = $this->actingAs($admin)
        ->getJson("/api/admin/users/{$restaurant1->id}/messenger-accounts/{$account->id}");

    $response->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Update
// ─────────────────────────────────────────────────────────────────────────────

test('admin can update a messenger account page name and status', function () {
    $admin = adminUser();
    $restaurant = restaurantUser();
    $account = MessengerAccount::factory()->create(['user_id' => $restaurant->id]);

    $response = $this->actingAs($admin)
        ->putJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}", [
            'page_name' => 'Updated Page Name',
            'status' => 'disabled',
        ]);

    $response->assertOk()->assertJsonPath('data.status', 'disabled');

    $this->assertDatabaseHas('messenger_accounts', [
        'id' => $account->id,
        'page_name' => 'Updated Page Name',
        'status' => 'disabled',
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Destroy
// ─────────────────────────────────────────────────────────────────────────────

test('admin can delete a messenger account', function () {
    $admin = adminUser();
    $restaurant = restaurantUser();
    $account = MessengerAccount::factory()->create(['user_id' => $restaurant->id]);

    $response = $this->actingAs($admin)
        ->deleteJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}");

    $response->assertOk()->assertJson(['status' => true]);

    $this->assertDatabaseMissing('messenger_accounts', ['id' => $account->id]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Regenerate Token
// ─────────────────────────────────────────────────────────────────────────────

test('admin can regenerate verify_token for a messenger account', function () {
    $admin = adminUser();
    $restaurant = restaurantUser();
    $account = MessengerAccount::factory()->create(['user_id' => $restaurant->id]);
    $oldToken = $account->verify_token;

    $response = $this->actingAs($admin)
        ->postJson("/api/admin/users/{$restaurant->id}/messenger-accounts/{$account->id}/regenerate-token");

    $response->assertOk()
        ->assertJsonStructure(['verify_token', 'webhook_url']);

    $newToken = $response->json('verify_token');

    expect($newToken)->not->toBe($oldToken);
    expect($newToken)->toMatch('/^[0-9a-f\-]{36}$/');
});
