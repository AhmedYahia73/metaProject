<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->superAdmin = User::create([
        'name' => 'Super Admin',
        'email' => 'admin@test.com',
        'password' => Hash::make('password123'),
        'role' => 'admin',
    ]);

    Sanctum::actingAs($this->superAdmin);
});

test('admin can create a new admin with automatic admin role', function () {
    $response = $this->postJson('/api/admin/admins', [
        'name' => 'Second Admin',
        'email' => 'second.admin@test.com',
        'password' => 'secret123',
    ]);

    $response->assertStatus(201);
    $response->assertJson([
        'status' => true,
        'message' => 'Admin created successfully.',
        'data' => [
            'name' => 'Second Admin',
            'email' => 'second.admin@test.com',
            'role' => 'admin',
        ],
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'second.admin@test.com',
        'role' => 'admin',
    ]);
});

test('admin listing only returns admin users', function () {
    User::create([
        'name' => 'Regular User',
        'phone' => '01011112222',
        'restuarant_name' => 'Test Restaurant',
        'password' => Hash::make('password123'),
        'role' => 'user',
    ]);

    $response = $this->getJson('/api/admin/admins');

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data.data');
    expect($response->json('data.data.0.role'))->toBe('admin');
});

test('admin can update an admin account', function () {
    $targetAdmin = User::create([
        'name' => 'Old Name',
        'email' => 'update.me@test.com',
        'password' => Hash::make('password123'),
        'role' => 'admin',
    ]);

    $response = $this->putJson("/api/admin/admins/{$targetAdmin->id}", [
        'name' => 'New Updated Name',
        'email' => 'updated.email@test.com',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'status' => true,
        'data' => [
            'name' => 'New Updated Name',
            'email' => 'updated.email@test.com',
            'role' => 'admin',
        ],
    ]);
});

test('admin cannot delete their own account', function () {
    $response = $this->deleteJson("/api/admin/admins/{$this->superAdmin->id}");

    $response->assertStatus(403);
});

test('admin can delete another admin account', function () {
    $anotherAdmin = User::create([
        'name' => 'To Be Deleted',
        'email' => 'delete.me@test.com',
        'password' => Hash::make('password123'),
        'role' => 'admin',
    ]);

    $response = $this->deleteJson("/api/admin/admins/{$anotherAdmin->id}");

    $response->assertStatus(200);
    $this->assertDatabaseMissing('users', ['id' => $anotherAdmin->id]);
});

test('user controller store automatically forces user role', function () {
    $response = $this->postJson('/api/admin/users', [
        'phone' => '01099998888',
        'password' => 'password123',
        'restuarant_name' => 'Al-Baraka Burger',
        'auto_request_code' => false,
    ]);

    $response->assertStatus(201);
    $response->assertJson([
        'status' => true,
        'data' => [
            'phone' => '01099998888',
            'restuarant_name' => 'Al-Baraka Burger',
            'role' => 'user',
        ],
    ]);

    $this->assertDatabaseHas('users', [
        'phone' => '01099998888',
        'role' => 'user',
    ]);
});
