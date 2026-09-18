<?php

use App\Mail\ContactUsMail;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('packages can be fetched without auth with arabic localization by default or query', function () {
    Package::factory()->create([
        'name' => [
            'en' => 'Silver Plan',
            'ar' => 'الباقة الفضية',
        ],
        'msg_number' => 1500,
        'price' => 49.99,
        'months' => 1,
    ]);

    $response = $this->getJson('/api/user/packages?lang=ar');

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('lang', 'ar')
        ->assertJsonPath('data.0.name', 'الباقة الفضية')
        ->assertJsonPath('data.0.names.en', 'Silver Plan')
        ->assertJsonPath('data.0.msg_number', 1500);
});

test('packages can be fetched with english localization', function () {
    Package::factory()->create([
        'name' => [
            'en' => 'Diamond Plan',
            'ar' => 'الباقة الماسية',
        ],
        'msg_number' => 10000,
        'price' => 199.99,
        'months' => 3,
    ]);

    $response = $this->getJson('/api/user/packages?lang=en');

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('lang', 'en')
        ->assertJsonPath('data.0.name', 'Diamond Plan')
        ->assertJsonPath('data.0.names.ar', 'الباقة الماسية');
});

test('contact us requires f_name, l_name, phone, email, and message', function () {
    $response = $this->postJson('/api/user/contact-us', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['f_name', 'l_name', 'phone', 'email', 'message']);
});

test('contact us sends email to My_Email successfully', function () {
    Mail::fake();

    config(['mail.my_email' => 'admin@example.com']);

    $payload = [
        'f_name' => 'Ahmed',
        'l_name' => 'Yahia',
        'phone' => '+201012345678',
        'email' => 'client@example.com',
        'message' => 'Hello, I would like to subscribe to the Gold package.',
    ];

    $response = $this->postJson('/api/user/contact-us', $payload);

    $response->assertOk()
        ->assertJson([
            'status' => true,
            'message' => 'Your message has been sent successfully.',
        ]);

    Mail::assertSent(ContactUsMail::class, function (ContactUsMail $mail) use ($payload) {
        return $mail->hasTo('admin@example.com')
            && $mail->data['f_name'] === $payload['f_name']
            && $mail->data['email'] === $payload['email']
            && $mail->hasReplyTo($payload['email'], 'Ahmed Yahia');
    });
});

test('contact us is rate limited to 2 requests per 5 minutes', function () {
    Mail::fake();

    $payload = [
        'f_name' => 'John',
        'l_name' => 'Doe',
        'phone' => '+1234567890',
        'email' => 'john@example.com',
        'message' => 'Testing rate limiting for contact form.',
    ];

    // Request 1: should pass
    $this->postJson('/api/user/contact-us', $payload)->assertOk();

    // Request 2: should pass
    $this->postJson('/api/user/contact-us', $payload)->assertOk();

    // Request 3: should be blocked by rate limiter with custom friendly message
    $response = $this->postJson('/api/user/contact-us', $payload)
        ->assertStatus(429)
        ->assertJsonStructure([
            'status',
            'message',
            'retry_after_seconds',
            'retry_after_minutes',
        ]);

    expect($response->json('status'))->toBeFalse()
        ->and($response->json('message'))->toContain('5');
});

test('dashboard requires authentication', function () {
    $this->getJson('/api/user/dashboard')
        ->assertStatus(401);
});

test('dashboard denies admin and requires user role', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/user/dashboard')
        ->assertStatus(403)
        ->assertJson([
            'status' => false,
            'message' => 'Forbidden: User access required.',
        ]);
});

test('dashboard allows users with user role', function () {
    $user = User::factory()->create([
        'role' => 'user',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/user/dashboard')
        ->assertOk()
        ->assertJson([
            'status' => true,
        ]);
});
