<?php

use App\Models\InstagramItem;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('instagram:subscribe {id?}', function (?string $id = null) {
    /** @var InstagramItem|null $item */
    $item = $id ? InstagramItem::find($id) : InstagramItem::where('status', 'active')->first();

    if (! $item) {
        $this->error('No active InstagramItem found in database.');

        return 1;
    }

    $this->info("Target account #{$item->id} (@{$item->username}) — Page ID: {$item->page_id}");

    if (empty($item->page_id) || empty($item->access_token)) {
        $this->error('Missing page_id or access_token on InstagramItem.');

        return 1;
    }

    $graphVersion = config('services.meta.graph_version', 'v21.0');

    $this->comment("Sending subscribed_apps request to Meta Graph API for Page {$item->page_id}...");

    $response = Http::post(
        "https://graph.facebook.com/{$graphVersion}/{$item->page_id}/subscribed_apps",
        [
            'subscribed_fields' => 'messages,messaging_postbacks,messaging_seen',
            'access_token' => $item->access_token,
        ]
    );

    $this->line('Meta POST response: '.$response->body());

    $verify = Http::get(
        "https://graph.facebook.com/{$graphVersion}/{$item->page_id}/subscribed_apps",
        [
            'access_token' => $item->access_token,
        ]
    );

    $this->line('Current subscriptions: '.$verify->body());

    if ($response->successful()) {
        $this->info('Successfully subscribed page to webhooks!');

        return 0;
    }

    $this->error('Failed to subscribe page.');

    return 1;
})->purpose('Subscribe InstagramItem connected page to Meta webhooks');
