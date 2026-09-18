<?php

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);
});

test('user listing is paginated properly with per_page and page parameters', function () {
    User::factory()->count(25)->create(['role' => 'user']);

    $response = $this->getJson('/api/admin/users?per_page=10&page=2');

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('pagination.per_page', 10);

    expect(count($response->json('data.data')))->toBe(10);
});

test('order listing is paginated properly with per_page and page parameters', function () {
    $user = User::factory()->create(['role' => 'user']);
    $package = Package::factory()->create();

    for ($i = 0; $i < 20; $i++) {
        Order::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'price' => 100,
            'total_discount' => 0,
            'total_tax' => 14,
            'final_price' => 114,
            'msgs' => 500,
            'from' => now()->toDateString(),
            'to' => now()->addMonth()->toDateString(),
        ]);
    }

    $response = $this->getJson('/api/admin/orders?per_page=5&page=3');

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('pagination.current_page', 3)
        ->assertJsonPath('pagination.per_page', 5)
        ->assertJsonPath('pagination.total', 20);

    expect(count($response->json('data.data')))->toBe(5);
});
