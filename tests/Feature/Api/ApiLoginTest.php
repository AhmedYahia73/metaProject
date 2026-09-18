<?php

use App\Models\User;

test('api user login validates required credentials', function () {
    $response = $this->postJson('/api/auth/user/login', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'phone', 'login', 'password']);
});

test('api user login allows valid user with email and password', function () {
    $user = User::factory()->create([
        'email' => 'client@meta.com',
        'role' => 'user',
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/api/auth/user/login', [
        'email' => 'client@meta.com',
        'password' => 'secret123',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('message', 'Logged in successfully.')
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'user',
                'token',
                'token_type',
            ],
        ]);
});

test('api user login rejects admin role user at user login endpoint', function () {
    $admin = User::factory()->create([
        'email' => 'admin@meta.com',
        'role' => 'admin',
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/api/auth/user/login', [
        'email' => 'admin@meta.com',
        'password' => 'secret123',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('status', false);
});
