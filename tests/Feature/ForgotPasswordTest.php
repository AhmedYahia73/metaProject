<?php

use App\Mail\ResetPasswordCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('forget_password fails when email does not exist', function () {
    $response = $this->postJson('/api/auth/forget-password', [
        'email' => 'nonexistent@test.com',
    ]);

    $response->assertBadRequest()
        ->assertJson([
            'status' => false,
            'message' => 'Email address not found.',
        ]);
});

test('forget_password generates code, saves to user, and sends email', function () {
    Mail::fake();

    $user = User::create([
        'name' => 'Sara Ali',
        'email' => 'sara@test.com',
        'password' => Hash::make('password123'),
        'phone' => '01011112222',
        'is_active' => true,
        'role' => 'user',
    ]);

    $response = $this->postJson('/api/auth/forget-password', [
        'email' => 'sara@test.com',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'message' => 'Password reset code sent to your email successfully.',
        ]);

    $user->refresh();
    expect($user->code)->not->toBeNull();
    expect(strlen((string) $user->code))->toBe(6);

    Mail::assertSent(ResetPasswordCodeMail::class, function ($mail) use ($user) {
        return $mail->hasTo('sara@test.com') && $mail->code === (string) $user->code;
    });
});

test('check_code returns true when email and code match', function () {
    User::create([
        'name' => 'Sara Ali',
        'email' => 'sara@test.com',
        'password' => Hash::make('password123'),
        'phone' => '01011112222',
        'code' => '654321',
        'is_active' => true,
        'role' => 'user',
    ]);

    $response = $this->postJson('/api/auth/check-code', [
        'email' => 'sara@test.com',
        'code' => '654321',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'is_valid' => true,
        ]);
});

test('check_code returns false when code is incorrect', function () {
    User::create([
        'name' => 'Sara Ali',
        'email' => 'sara@test.com',
        'password' => Hash::make('password123'),
        'phone' => '01011112222',
        'code' => '654321',
        'is_active' => true,
        'role' => 'user',
    ]);

    $response = $this->postJson('/api/auth/check-code', [
        'email' => 'sara@test.com',
        'code' => '000000',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => false,
            'is_valid' => false,
        ]);
});

test('check_code returns false when code is null in database', function () {
    User::create([
        'name' => 'Sara Ali',
        'email' => 'sara@test.com',
        'password' => Hash::make('password123'),
        'phone' => '01011112222',
        'code' => null,
        'is_active' => true,
        'role' => 'user',
    ]);

    $response = $this->postJson('/api/auth/check-code', [
        'email' => 'sara@test.com',
        'code' => '123456',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => false,
            'is_valid' => false,
        ]);
});

test('change_password fails when code is incorrect', function () {
    User::create([
        'name' => 'Sara Ali',
        'email' => 'sara@test.com',
        'password' => Hash::make('oldpassword'),
        'phone' => '01011112222',
        'code' => '654321',
        'is_active' => true,
        'role' => 'user',
    ]);

    $response = $this->postJson('/api/auth/change-password', [
        'email' => 'sara@test.com',
        'code' => '999999',
        'password' => 'newpassword123',
    ]);

    $response->assertBadRequest()
        ->assertJson([
            'status' => false,
            'message' => 'Invalid or expired code.',
        ]);
});

test('change_password successfully updates password and clears code', function () {
    $user = User::create([
        'name' => 'Sara Ali',
        'email' => 'sara@test.com',
        'password' => Hash::make('oldpassword'),
        'phone' => '01011112222',
        'code' => '654321',
        'is_active' => true,
        'role' => 'user',
    ]);

    $response = $this->postJson('/api/auth/change-password', [
        'email' => 'sara@test.com',
        'code' => '654321',
        'password' => 'newbrandpassword',
        'password_confirmation' => 'newbrandpassword',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'message' => 'Password changed successfully.',
        ]);

    $user->refresh();
    expect($user->code)->toBeNull();
    expect(Hash::check('newbrandpassword', $user->password))->toBeTrue();
});
