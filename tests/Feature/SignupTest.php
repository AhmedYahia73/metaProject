<?php

use App\Mail\ActivationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('signup fails when required fields are missing', function () {
    $response = $this->postJson('/api/auth/signup', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password', 'phone']);
});

test('signup fails when email format is invalid or password too short', function () {
    $response = $this->postJson('/api/auth/signup', [
        'name' => 'John Doe',
        'email' => 'invalid-email',
        'password' => '123',
        'phone' => '01000000001',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('signup successfully creates new inactive user and sends activation email', function () {
    Mail::fake();

    $payload = [
        'name' => 'Ahmed Test',
        'email' => 'ahmed@test.com',
        'password' => 'secret123',
        'phone' => '01011112222',
    ];

    $response = $this->postJson('/api/auth/signup', $payload);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'message' => 'Verification code sent to your email successfully.',
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'ahmed@test.com',
        'phone' => '01011112222',
        'name' => 'Ahmed Test',
        'is_active' => false,
        'role' => 'user',
    ]);

    $user = User::where('email', 'ahmed@test.com')->first();
    expect($user)->not->toBeNull();
    expect($user->code)->not->toBeNull();
    expect(strlen((string) $user->code))->toBe(6);
    expect(Hash::check('secret123', $user->password))->toBeTrue();

    Mail::assertSent(ActivationCodeMail::class, function ($mail) use ($user) {
        return $mail->hasTo('ahmed@test.com') && $mail->code === (string) $user->code;
    });
});

test('signup updates existing inactive user with new code and details and sends email', function () {
    Mail::fake();

    $existing = User::create([
        'name' => 'Old Inactive',
        'email' => 'inactive@test.com',
        'password' => Hash::make('oldpassword'),
        'phone' => '01099998888',
        'code' => '000000',
        'is_active' => false,
        'role' => 'user',
    ]);

    $payload = [
        'name' => 'Updated Name',
        'email' => 'inactive@test.com',
        'password' => 'newpassword123',
        'phone' => '01099998888',
    ];

    $response = $this->postJson('/api/auth/signup', $payload);

    $response->assertOk()
        ->assertJson(['status' => true]);

    $existing->refresh();
    expect($existing->name)->toBe('Updated Name');
    expect($existing->code)->not->toBe('000000');
    expect($existing->is_active)->toBeFalse();
    expect(Hash::check('newpassword123', $existing->password))->toBeTrue();

    Mail::assertSent(ActivationCodeMail::class, function ($mail) use ($existing) {
        return $mail->hasTo('inactive@test.com') && $mail->code === (string) $existing->code;
    });
});

test('signup fails if email is already active', function () {
    Mail::fake();

    User::create([
        'name' => 'Active User',
        'email' => 'active@test.com',
        'password' => Hash::make('password'),
        'phone' => '01012345678',
        'is_active' => true,
        'role' => 'user',
    ]);

    $response = $this->postJson('/api/auth/signup', [
        'name' => 'Attempt User',
        'email' => 'active@test.com',
        'password' => 'password123',
        'phone' => '01087654321',
    ]);

    $response->assertBadRequest()
        ->assertJson([
            'status' => false,
            'message' => 'Email is already registered.',
        ]);

    Mail::assertNothingSent();
});

test('signup fails if email belongs to an admin', function () {
    Mail::fake();

    User::create([
        'name' => 'Admin User',
        'email' => 'admin@test.com',
        'password' => Hash::make('password'),
        'phone' => '01000000099',
        'is_active' => false,
        'role' => 'admin',
    ]);

    $response = $this->postJson('/api/auth/signup', [
        'name' => 'Attempt Admin',
        'email' => 'admin@test.com',
        'password' => 'password123',
        'phone' => '01087654322',
    ]);

    $response->assertBadRequest()
        ->assertJson([
            'status' => false,
            'message' => 'Email is already registered.',
        ]);

    Mail::assertNothingSent();
});

test('signup fails if phone number belongs to another user', function () {
    Mail::fake();

    User::create([
        'name' => 'Existing User',
        'email' => 'first@test.com',
        'password' => Hash::make('password'),
        'phone' => '01055554444',
        'is_active' => false,
        'role' => 'user',
    ]);

    $response = $this->postJson('/api/auth/signup', [
        'name' => 'Second User',
        'email' => 'second@test.com',
        'password' => 'password123',
        'phone' => '01055554444',
    ]);

    $response->assertBadRequest()
        ->assertJson([
            'status' => false,
            'message' => 'Phone number is already registered.',
        ]);

    Mail::assertNothingSent();
});
