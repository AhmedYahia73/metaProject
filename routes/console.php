<?php

use App\Models\InstagramItem;
use App\Models\User;
use App\Services\MetaPageTokenService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

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
            'subscribed_fields' => 'messages,messaging_postbacks,message_reads,feed,comments',
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

Artisan::command('instagram:update-token {token} {--account=4} {--user=4}', function (string $token) {
    $cleanToken = MetaPageTokenService::sanitizeToken($token);
    $accountId = (int) ($this->option('account') ?: 4);
    $userId = (int) ($this->option('user') ?: 4);

    $this->info('Sanitized token length: '.strlen((string) $cleanToken));

    $user = User::find($userId);
    if ($user) {
        $user->update(['facebook_access_token' => $cleanToken]);
        $this->info("Updated User #{$user->id} facebook_access_token.");
    }

    $item = InstagramItem::find($accountId);
    if ($item) {
        $item->update(['access_token' => $cleanToken]);
        $this->info("Updated InstagramItem #{$item->id} access_token.");
    }

    if ($user) {
        /** @var MetaPageTokenService $service */
        $service = app(MetaPageTokenService::class);
        $syncResult = $service->syncUserPagesAndTokens($user, $cleanToken);
        $this->info('Meta sync result: '.json_encode($syncResult));
        if ($item) {
            $item->refresh();
            $this->info("InstagramItem #{$item->id} current token: ".substr((string) $item->access_token, 0, 20).'...');
        }
    }

    $this->info('Done!');

    return 0;
})->purpose('Sanitize and update Facebook/Instagram token for User and InstagramItem');

// Schedule polling for Instagram comments every minute as a reliable automation fallback
Schedule::command('ig:poll-comments')->everyMinute()->withoutOverlapping(10);
