<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('auth-action');
});

test('signup is throttled after 3 requests in 5 minutes', function () {
    Mail::fake();

    $payload = [
        'name' => 'Test User',
        'email' => 'throttle_signup@test.com',
        'password' => 'secret123',
        'phone' => '01012345001',
    ];

    // Attempt 1, 2, 3 should pass
    $this->postJson('/api/auth/signup', $payload)->assertOk();
    $this->postJson('/api/auth/signup', $payload)->assertOk();
    $this->postJson('/api/auth/signup', $payload)->assertOk();

    // Attempt 4 should be throttled (429)
    $response = $this->postJson('/api/auth/signup', $payload);
    $response->assertStatus(429)
        ->assertJson(['status' => false]);
});

test('forget_password is throttled after 3 requests in 5 minutes', function () {
    Mail::fake();

    User::create([
        'name' => 'User One',
        'email' => 'fp_throttle@test.com',
        'password' => Hash::make('password123'),
        'phone' => '01012345002',
        'is_active' => true,
        'role' => 'user',
    ]);

    $payload = ['email' => 'fp_throttle@test.com'];

    $this->postJson('/api/auth/forget-password', $payload)->assertOk();
    $this->postJson('/api/auth/forget-password', $payload)->assertOk();
    $this->postJson('/api/auth/forget-password', $payload)->assertOk();

    $response = $this->postJson('/api/auth/forget-password', $payload);
    $response->assertStatus(429)
        ->assertJson(['status' => false]);
});

test('check_code is throttled after 3 requests in 5 minutes', function () {
    User::create([
        'name' => 'User Two',
        'email' => 'check_throttle@test.com',
        'password' => Hash::make('password123'),
        'phone' => '01012345003',
        'code' => '123456',
        'is_active' => true,
        'role' => 'user',
    ]);

    $payload = ['email' => 'check_throttle@test.com', 'code' => '123456'];

    $this->postJson('/api/auth/check-code', $payload)->assertOk();
    $this->postJson('/api/auth/check-code', $payload)->assertOk();
    $this->postJson('/api/auth/check-code', $payload)->assertOk();

    $response = $this->postJson('/api/auth/check-code', $payload);
    $response->assertStatus(429)
        ->assertJson(['status' => false]);
});

test('change_password is throttled after 3 requests in 5 minutes', function () {
    User::create([
        'name' => 'User Three',
        'email' => 'change_throttle@test.com',
        'password' => Hash::make('oldpassword'),
        'phone' => '01012345004',
        'code' => '123456',
        'is_active' => true,
        'role' => 'user',
    ]);

    $payload = [
        'email' => 'change_throttle@test.com',
        'code' => '123456',
        'password' => 'newpassword123',
    ];

    $this->postJson('/api/auth/change-password', $payload)->assertOk();
    $this->postJson('/api/auth/change-password', $payload)->assertBadRequest();
    $this->postJson('/api/auth/change-password', $payload)->assertBadRequest();

    $response = $this->postJson('/api/auth/change-password', $payload);
    $response->assertStatus(429)
        ->assertJson(['status' => false]);
});

test('userLogin is blocked on the 6th failed attempt', function () {
    User::create([
        'name' => 'Normal User',
        'phone' => '01099990001',
        'password' => Hash::make('correct_password'),
        'role' => 'user',
    ]);

    $wrongPayload = [
        'phone' => '01099990001',
        'password' => 'wrong_password',
    ];

    // 5 failed attempts
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/user/login', $wrongPayload)->assertUnauthorized();
    }

    // 6th attempt should be blocked with 429
    $response = $this->postJson('/api/auth/user/login', $wrongPayload);
    $response->assertStatus(429)
        ->assertJson([
            'status' => false,
        ]);
});

test('adminLogin is blocked on the 6th failed attempt', function () {
    User::create([
        'name' => 'Admin User',
        'email' => 'admin_test@test.com',
        'password' => Hash::make('correct_password'),
        'role' => 'admin',
    ]);

    $wrongPayload = [
        'email' => 'admin_test@test.com',
        'password' => 'wrong_password',
    ];

    // 5 failed attempts
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/admin/login', $wrongPayload)->assertUnauthorized();
    }

    // 6th attempt should be blocked with 429
    $response = $this->postJson('/api/auth/admin/login', $wrongPayload);
    $response->assertStatus(429)
        ->assertJson([
            'status' => false,
        ]);
});

test('userLogin can succeed multiple times without being blocked if credentials are correct', function () {
    User::create([
        'name' => 'Active User',
        'phone' => '01088880001',
        'password' => Hash::make('my_password_123'),
        'role' => 'user',
    ]);

    $correctPayload = [
        'phone' => '01088880001',
        'password' => 'my_password_123',
    ];

    // Successfully login 8 times (more than 5 times)
    for ($i = 0; $i < 8; $i++) {
        $response = $this->postJson('/api/auth/user/login', $correctPayload);
        $response->assertOk()
            ->assertJson(['status' => true]);
    }
});

test('adminLogin can succeed multiple times without being blocked if credentials are correct', function () {
    User::create([
        'name' => 'Super Admin',
        'email' => 'superadmin@test.com',
        'password' => Hash::make('admin_password_123'),
        'role' => 'admin',
    ]);

    $correctPayload = [
        'email' => 'superadmin@test.com',
        'password' => 'admin_password_123',
    ];

    // Successfully login 8 times (more than 5 times)
    for ($i = 0; $i < 8; $i++) {
        $response = $this->postJson('/api/auth/admin/login', $correctPayload);
        $response->assertOk()
            ->assertJson(['status' => true]);
    }
});

test('each endpoint has an independent rate limiter and does not consume others attempts', function () {
    Mail::fake();

    User::create([
        'name' => 'Independent User',
        'email' => 'independent@test.com',
        'password' => Hash::make('password123'),
        'phone' => '01011119999',
        'code' => '123456',
        'is_active' => true,
        'role' => 'user',
    ]);

    // Exhaust signup (3 attempts)
    $signupPayload = [
        'name' => 'New Guy',
        'email' => 'newguy@test.com',
        'password' => 'secret123',
        'phone' => '01022223333',
    ];
    $this->postJson('/api/auth/signup', $signupPayload)->assertOk();
    $this->postJson('/api/auth/signup', $signupPayload)->assertOk();
    $this->postJson('/api/auth/signup', $signupPayload)->assertOk();
    $this->postJson('/api/auth/signup', $signupPayload)->assertStatus(429); // signup is now blocked!

    // Even though signup is blocked, forget-password STILL works and has its own 3 attempts!
    $this->postJson('/api/auth/forget-password', ['email' => 'independent@test.com'])->assertOk();

    $user->refresh();
    $newCode = (string) $user->code;

    // check-code STILL works and has its own 3 attempts!
    $this->postJson('/api/auth/check-code', ['email' => 'independent@test.com', 'code' => $newCode])->assertOk();

    // change-password STILL works and has its own 3 attempts!
    $this->postJson('/api/auth/change-password', [
        'email' => 'independent@test.com',
        'code' => $newCode,
        'password' => 'newpassword123',
    ])->assertOk();

    // userLogin STILL works!
    $this->postJson('/api/auth/user/login', [
        'phone' => '01011119999',
        'password' => 'newpassword123',
    ])->assertOk();
});

