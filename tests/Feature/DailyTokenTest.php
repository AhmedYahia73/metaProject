<?php

use App\Models\DailyToken;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\WhatsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it records and increments daily tokens for WhatsApp item', function () {
    $whatsItem = WhatsItem::factory()->create();

    // First call: creates new record for today
    $record1 = DailyToken::recordForWhats($whatsItem->id, 150);

    expect($record1)->toBeInstanceOf(DailyToken::class)
        ->and($record1->whats_item_id)->toBe($whatsItem->id)
        ->and($record1->date->toDateString())->toBe(now()->toDateString())
        ->and((int) $record1->tokens)->toBe(150);

    // Second call on the same day: increments tokens
    $record2 = DailyToken::recordForWhats($whatsItem->id, 250);

    $record2->refresh();
    expect((int) $record2->tokens)->toBe(400)
        ->and(DailyToken::where('whats_item_id', $whatsItem->id)->count())->toBe(1);
});

test('it records and increments daily tokens for Messenger account', function () {
    $messengerAccount = MessengerAccount::factory()->create();

    // First call
    $record1 = DailyToken::recordForMessenger($messengerAccount->id, 200);

    expect($record1)->toBeInstanceOf(DailyToken::class)
        ->and($record1->messenger_account_id)->toBe($messengerAccount->id)
        ->and((int) $record1->tokens)->toBe(200);

    // Second call
    $record2 = DailyToken::recordForMessenger($messengerAccount->id, 300);

    $record2->refresh();
    expect((int) $record2->tokens)->toBe(500)
        ->and(DailyToken::where('messenger_account_id', $messengerAccount->id)->count())->toBe(1);
});

test('it records and increments daily tokens for Instagram item', function () {
    $instagramItem = InstagramItem::factory()->create();

    // First call
    $record1 = DailyToken::recordForInstagram($instagramItem->id, 500);

    expect($record1)->toBeInstanceOf(DailyToken::class)
        ->and($record1->instagram_item_id)->toBe($instagramItem->id)
        ->and((int) $record1->tokens)->toBe(500);

    // Second call
    $record2 = DailyToken::recordForInstagram($instagramItem->id, 150);

    $record2->refresh();
    expect((int) $record2->tokens)->toBe(650)
        ->and(DailyToken::where('instagram_item_id', $instagramItem->id)->count())->toBe(1);
});

test('it verifies relationships between DailyToken and channel models', function () {
    $whatsItem = WhatsItem::factory()->create();
    $messengerAccount = MessengerAccount::factory()->create();
    $instagramItem = InstagramItem::factory()->create();

    DailyToken::recordForWhats($whatsItem->id, 100);
    DailyToken::recordForMessenger($messengerAccount->id, 200);
    DailyToken::recordForInstagram($instagramItem->id, 300);

    expect($whatsItem->dailyTokens)->toHaveCount(1)
        ->and($whatsItem->dailyTokens->first()->tokens)->toBe(100)
        ->and($messengerAccount->dailyTokens)->toHaveCount(1)
        ->and($messengerAccount->dailyTokens->first()->tokens)->toBe(200)
        ->and($instagramItem->dailyTokens)->toHaveCount(1)
        ->and($instagramItem->dailyTokens->first()->tokens)->toBe(300);

    $tokenRecord = DailyToken::where('whats_item_id', $whatsItem->id)->first();
    expect($tokenRecord->whatsItem->id)->toBe($whatsItem->id);
});
