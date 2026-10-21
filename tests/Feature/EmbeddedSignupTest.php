<?php

use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ── Set required Meta config for all tests ────────────────────────────────────
beforeEach(function () {
    config([
        'services.meta.app_id'     => 'TEST_APP_ID',
        'services.meta.app_secret' => 'TEST_APP_SECRET',
    ]);
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeUser(): User
{
    return User::factory()->create([
        'role'            => 'user',
        'restuarant_name' => 'Test Restaurant',
    ]);
}

function makeWhatsItem(User $user, array $overrides = []): WhatsItem
{
    return WhatsItem::factory()->create(array_merge([
        'user_id'         => $user->id,
        'phone'           => '+201001234567',
        'phone_number_id' => 'pnid_test_123',
        'waba_id'         => 'waba_test_456',
        'access_token'    => 'TOKEN_SYSTEM',
        'token_type'      => 'system',
        'phone_status'    => 'active',
        'msg_number'      => 100,
        'start_date'      => now()->subDay(),
        'end_date'        => now()->addMonth(),
    ], $overrides));
}

// ── Embedded Signup — completeSignup ─────────────────────────────────────────

test('embedded signup returns 400 when code exchange fails', function () {
    $user = makeUser();

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Invalid code', 'type' => 'OAuthException', 'code' => 100],
        ], 400),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/user/whats/embedded-signup', ['code' => 'bad_code']);

    $response->assertStatus(400)
        ->assertJson(['status' => false]);
});

test('embedded signup returns 422 when no WABA is granted', function () {
    $user = makeUser();

    // Sequence: 1st call = token exchange, 2nd call = debug_token
    Http::fake([
        'graph.facebook.com/*' => Http::sequence()
            ->push(['access_token' => 'CUSTOMER_TOKEN', 'token_type' => 'bearer'], 200)
            ->push(['data' => ['granular_scopes' => []]], 200),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/user/whats/embedded-signup', ['code' => 'some_code']);

    $response->assertStatus(422)
        ->assertJson(['status' => false]);
});

test('embedded signup creates WhatsItem and subscribes webhook on success', function () {
    $user = makeUser();

    // Sequence of all Meta API calls in order:
    // 1. oauth/access_token  2. debug_token  3. WABA/phone_numbers  4. phone details  5. subscribed_apps
    Http::fake([
        'graph.facebook.com/*' => Http::sequence()
            ->push(['access_token' => 'CUSTOMER_TOKEN', 'token_type' => 'bearer'], 200)
            ->push(['data' => ['granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA_ID_999']]]]], 200)
            ->push(['data' => [['id' => 'PNID_888']]], 200)
            ->push(['id' => 'PNID_888', 'display_phone_number' => '+20100999888', 'verified_name' => 'My Restaurant', 'code_verification_status' => 'VERIFIED', 'status' => 'CONNECTED'], 200)
            ->push(['success' => true], 200),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/user/whats/embedded-signup', ['code' => 'valid_code']);

    $response->assertStatus(201)
        ->assertJson([
            'status' => true,
            'meta'   => [
                'waba_id'            => 'WABA_ID_999',
                'phone_number_id'    => 'PNID_888',
                'webhook_subscribed' => true,
            ],
        ]);

    $this->assertDatabaseHas('whats_items', [
        'user_id'          => $user->id,
        'phone_number_id'  => 'PNID_888',
        'waba_id'          => 'WABA_ID_999',
        'customer_waba_id' => 'WABA_ID_999',
        'token_type'       => 'customer',
        'phone_status'     => 'active',
    ]);
});

test('embedded signup updates existing WhatsItem when phone already exists', function () {
    $user = makeUser();

    $existing = makeWhatsItem($user, [
        'phone'           => '+20100999888',
        'phone_number_id' => 'PNID_OLD',
        'token_type'      => 'system',
    ]);

    // Sequence: token exchange → debug_token → phone_numbers → phone details → subscribed_apps
    Http::fake([
        'graph.facebook.com/*' => Http::sequence()
            ->push(['access_token' => 'NEW_CUSTOMER_TOKEN', 'token_type' => 'bearer'], 200)
            ->push(['data' => ['granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA_ID_999']]]]], 200)
            ->push(['data' => [['id' => 'PNID_888']]], 200)
            ->push(['id' => 'PNID_888', 'display_phone_number' => '+20100999888', 'verified_name' => 'My Restaurant', 'code_verification_status' => 'VERIFIED', 'status' => 'CONNECTED'], 200)
            ->push(['success' => true], 200),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/user/whats/embedded-signup', ['code' => 'valid_code']);

    $response->assertOk()->assertJson(['status' => true]);

    // Should update the existing record, not create a new one
    $this->assertDatabaseCount('whats_items', 1);

    $this->assertDatabaseHas('whats_items', [
        'id'              => $existing->id,
        'token_type'      => 'customer',
        'phone_number_id' => 'PNID_888',
    ]);
});

// ── resubscribeWebhook ────────────────────────────────────────────────────────

test('resubscribe webhook succeeds for customer token items', function () {
    $user = makeUser();

    $whatsItem = makeWhatsItem($user, [
        'token_type'       => 'customer',
        'customer_waba_id' => 'WABA_ID_999',
        'access_token'     => 'CUSTOMER_TOKEN',
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/user/whats/items/{$whatsItem->id}/resubscribe-webhook");

    $response->assertOk()->assertJson(['status' => true]);
});

test('resubscribe webhook returns 400 for system token items', function () {
    $user = makeUser();
    $whatsItem = makeWhatsItem($user, ['token_type' => 'system', 'customer_waba_id' => null]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/user/whats/items/{$whatsItem->id}/resubscribe-webhook");

    $response->assertStatus(400)->assertJson(['status' => false]);
});

// ── resolveWhatsToken: Embedded Signup numbers use their own token ─────────────

test('webhook uses customer token for embedded signup numbers', function () {
    $user = makeUser();

    $whatsItem = makeWhatsItem($user, [
        'phone'            => '201001234567',
        'phone_number_id'  => 'PNID_CUSTOMER',
        'waba_id'          => 'WABA_CUSTOMER',
        'customer_waba_id' => 'WABA_CUSTOMER',
        'access_token'     => 'CUSTOMER_OWN_TOKEN',
        'token_type'       => 'customer',
    ]);

    Http::fake([
        "https://graph.facebook.com/*/PNID_CUSTOMER/messages" => Http::response(['messages' => [['id' => 'wamid.test']]], 200),
        'graph.facebook.com/*' => Http::response([], 200),
    ]);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'id'      => 'WABA_CUSTOMER',
            'changes' => [[
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata'          => [
                        'display_phone_number' => '201001234567',
                        'phone_number_id'      => 'PNID_CUSTOMER',
                    ],
                    'contacts' => [['profile' => ['name' => 'Ahmed'], 'wa_id' => '201009876543']],
                    'messages' => [[
                        'from'      => '201009876543',
                        'id'        => 'wamid.in.1',
                        'timestamp' => (string) time(),
                        'type'      => 'text',
                        'text'      => ['body' => 'مرحبا'],
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];

    $response = $this->postJson('/api/web-hook', $payload);

    $response->assertOk();

    // Confirm the CUSTOMER token was used for the send call
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'PNID_CUSTOMER/messages')
            && $request->header('Authorization')[0] === 'Bearer CUSTOMER_OWN_TOKEN';
    });
});
