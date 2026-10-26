<?php

use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('deleting whats item deregisters phone number from meta and unsubscribes waba', function () {
    $user = User::factory()->create(['role' => 'user']);
    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone' => '201012345678',
        'phone_number_id' => '109988776655443',
        'customer_waba_id' => '209988776655443',
        'token_type' => 'customer',
        'access_token' => 'EAACustomerTokenForTest',
    ]);

    Http::fake([
        'https://graph.facebook.com/*/109988776655443/deregister' => Http::response(['success' => true], 200),
        'https://graph.facebook.com/*/209988776655443/subscribed_apps' => Http::response(['success' => true], 200),
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/user/whats/items/{$item->id}");

    $response->assertOk()
        ->assertJson([
            'status' => true,
        ]);

    // Verify Meta deregister was called
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/109988776655443/deregister')
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer EAACustomerTokenForTest');
    });

    // Verify Meta WABA unsubscribe was called
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/209988776655443/subscribed_apps')
            && $request->method() === 'DELETE'
            && $request->hasHeader('Authorization', 'Bearer EAACustomerTokenForTest');
    });

    // Verify database record is deleted
    $this->assertDatabaseMissing('whats_items', ['id' => $item->id]);
});

test('admin deleting whats item also deregisters phone from meta', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'user']);
    $item = WhatsItem::factory()->create([
        'user_id' => $user->id,
        'phone_number_id' => '9988776655',
    ]);

    Http::fake([
        'https://graph.facebook.com/*/9988776655/deregister' => Http::response(['success' => true], 200),
    ]);

    $response = $this->actingAs($admin)
        ->deleteJson("/api/admin/users/{$user->id}/whats-items/{$item->id}");

    $response->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/9988776655/deregister')
            && $request->method() === 'POST';
    });

    $this->assertDatabaseMissing('whats_items', ['id' => $item->id]);
});
