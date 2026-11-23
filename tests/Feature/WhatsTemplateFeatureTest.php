<?php

use App\Jobs\SendClientBulkMessagesJob;
use App\Jobs\SendClientMessageChunkJob;
use App\Models\Client;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\WhatsItem;
use App\Services\MetaWhatsAppService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'user']);
    $this->admin = User::factory()->create(['role' => 'admin']);
});

test('order approval activates t_start_date, t_end_date, t_msg_number when is_template is true', function () {
    Sanctum::actingAs($this->admin);

    $whatsItem = WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '201011112222',
        'phone_status' => 'verified',
        'msg_number' => 0,
        't_msg_number' => 0,
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة تمبلت', 'en' => 'Template Package'],
        'type' => 'whats',
        'is_template' => true,
        'msg_number' => 500,
        'price' => 100,
        'months' => 2,
    ]);

    $order = Order::create([
        'user_id' => $this->user->id,
        'package_id' => $package->id,
        'channel' => 'whatsapp',
        'is_template' => true,
        'whats_item_id' => $whatsItem->id,
        'status' => 'pending',
        'msgs' => 500,
        'price' => 100,
        'final_price' => 100,
    ]);

    $response = $this->postJson("/api/admin/orders/{$order->id}/approve");

    $response->assertOk()
        ->assertJsonPath('status', true);

    $whatsItem->refresh();

    expect($whatsItem->t_msg_number)->toBe(500)
        ->and($whatsItem->t_start_date->toDateString())->toBe(Carbon::today()->toDateString())
        ->and($whatsItem->t_end_date->toDateString())->toBe(Carbon::today()->addMonths(2)->toDateString())
        ->and($whatsItem->msg_number)->toBe(0); // Regular msg_number remains untouched
});

test('admin can directly activate a template package on a whats item', function () {
    Sanctum::actingAs($this->admin);

    $whatsItem = WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '201099998888',
        'phone_status' => 'active',
        't_msg_number' => 100,
    ]);

    $package = Package::create([
        'name' => ['ar' => 'باقة رسائل تمبلت', 'en' => 'Template Pack'],
        'type' => 'whats',
        'is_template' => true,
        'msg_number' => 1000,
        'price' => 200,
        'months' => 1,
    ]);

    $response = $this->postJson("/api/admin/users/{$this->user->id}/whats-items/{$whatsItem->id}/activate-package", [
        'package_id' => $package->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.t_msg_number', 1100);

    $whatsItem->refresh();
    expect($whatsItem->t_msg_number)->toBe(1100)
        ->and($whatsItem->t_start_date->toDateString())->toBe(Carbon::today()->toDateString())
        ->and($whatsItem->t_end_date->toDateString())->toBe(Carbon::today()->addMonth()->toDateString());
});

test('user can list their whats items with is_package_active status key', function () {
    Sanctum::actingAs($this->user);

    // Active item
    WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '201011111111',
        'phone_status' => 'active',
        't_msg_number' => 150,
        't_start_date' => Carbon::yesterday()->toDateString(),
        't_end_date' => Carbon::tomorrow()->toDateString(),
    ]);

    // Expired item
    WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '201022222222',
        'phone_status' => 'active',
        't_msg_number' => 50,
        't_start_date' => Carbon::today()->subMonths(2)->toDateString(),
        't_end_date' => Carbon::today()->subMonth()->toDateString(),
    ]);

    $response = $this->getJson('/api/user/whats/template-status-items');

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonCount(2, 'data');

    $items = collect($response->json('data'));
    $activeItem = $items->firstWhere('phone', '201011111111');
    $expiredItem = $items->firstWhere('phone', '201022222222');

    expect($activeItem['is_package_active'])->toBeTrue()
        ->and($activeItem['t_msg_number'])->toBe(150)
        ->and($expiredItem['is_package_active'])->toBeFalse();
});

test('user cannot send messages if date range is expired or quota is insufficient', function () {
    Sanctum::actingAs($this->user);

    $whatsItem = WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '201033333333',
        'phone_status' => 'active',
        't_msg_number' => 2,
        't_start_date' => Carbon::today()->subMonths(2)->toDateString(),
        't_end_date' => Carbon::today()->subMonth()->toDateString(), // Expired
    ]);

    $client1 = Client::factory()->create(['user_id' => $this->user->id]);
    $client2 = Client::factory()->create(['user_id' => $this->user->id]);

    // Test expired dates
    $response = $this->postJson('/api/user/clients/send-messages', [
        'whats_item_id' => $whatsItem->id,
        'client_ids' => [$client1->id, $client2->id],
        'msg' => 'Hello special customer',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('status', false);

    // Update to valid dates but insufficient quota (have 1, need 2)
    $whatsItem->update([
        't_start_date' => Carbon::yesterday()->toDateString(),
        't_end_date' => Carbon::tomorrow()->toDateString(),
        't_msg_number' => 1,
    ]);

    $responseQuota = $this->postJson('/api/user/clients/send-messages', [
        'whats_item_id' => $whatsItem->id,
        'client_ids' => [$client1->id, $client2->id],
        'msg' => 'Hello special customer',
    ]);

    $responseQuota->assertUnprocessable()
        ->assertJsonPath('status', false);
});

test('user can queue bulk messages when quota and date range are valid', function () {
    Queue::fake();
    Sanctum::actingAs($this->user);

    $whatsItem = WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '201044444444',
        'phone_status' => 'active',
        't_msg_number' => 10,
        't_start_date' => Carbon::yesterday()->toDateString(),
        't_end_date' => Carbon::tomorrow()->toDateString(),
    ]);

    $clients = Client::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->postJson('/api/user/clients/send-messages', [
        'whats_item_id' => $whatsItem->id,
        'client_ids' => $clients->pluck('id')->all(),
        'msg' => 'Welcome to our store!',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.total_clients', 3)
        ->assertJsonPath('data.batches_count', 1);

    Queue::assertPushed(SendClientBulkMessagesJob::class, function ($job) use ($whatsItem) {
        return $job->whatsItemId === $whatsItem->id;
    });
});

test('chunk job only deducts t_msg_number when sending actually succeeds', function () {
    $whatsItem = WhatsItem::create([
        'user_id' => $this->user->id,
        'phone' => '201055555555',
        'phone_status' => 'active',
        't_msg_number' => 10,
        't_start_date' => Carbon::yesterday()->toDateString(),
        't_end_date' => Carbon::tomorrow()->toDateString(),
    ]);

    $clientSuccess = Client::factory()->create([
        'user_id' => $this->user->id,
        'whats_number' => '201011111111',
    ]);
    $clientFail = Client::factory()->create([
        'user_id' => $this->user->id,
        'whats_number' => '201022222222',
    ]);

    // Mock MetaWhatsAppService
    $mockService = mock(MetaWhatsAppService::class);
    $mockService->shouldReceive('ensureBroadcastTemplate')->andReturn(['success' => true]);

    // Success for client 1
    $mockService->shouldReceive('sendBroadcastTemplateMessage')
        ->with(Mockery::on(fn ($item) => $item->id === $whatsItem->id), '201011111111', 'Testing msg')
        ->once()
        ->andReturn(['success' => true, 'message_id' => 'wamid.123']);

    // Failure for client 2
    $mockService->shouldReceive('sendBroadcastTemplateMessage')
        ->with(Mockery::on(fn ($item) => $item->id === $whatsItem->id), '201022222222', 'Testing msg')
        ->once()
        ->andReturn(['success' => false, 'message' => 'Rate limit exceeded']);

    $job = new SendClientMessageChunkJob(
        $whatsItem->id,
        [$clientSuccess->id, $clientFail->id],
        'Testing msg'
    );

    $job->handle($mockService);

    $whatsItem->refresh();

    // Initial was 10. Only 1 succeeded, so 1 was deducted => 9
    expect($whatsItem->t_msg_number)->toBe(9);
});
