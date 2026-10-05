<?php

namespace App\Console\Commands;

use App\Http\Controllers\api\HomeController;
use App\Models\InstagramItem;
use App\Services\MetaPageTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PollIgComments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ig:poll-comments
                            {--account= : Specific InstagramItem ID to poll}
                            {--limit=10 : Number of recent media posts to inspect per account}
                            {--skip-existing : Mark all current comments as processed without sending replies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll Instagram Graph API for new media comments and process auto-replies (public reply + private DM)';

    /**
     * Execute the console command.
     */
    public function handle(MetaPageTokenService $tokenService): int
    {
        $accountId = $this->option('account');
        $mediaLimit = max(1, (int) $this->option('limit'));
        $skipExisting = (bool) $this->option('skip-existing');

        $query = InstagramItem::query()->where('status', 'active')->whereNotNull('access_token');
        if ($accountId) {
            $query->where('id', $accountId);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            $this->comment('No active Instagram accounts found to poll.');

            return 0;
        }

        $graphVersion = config('services.meta.graph_version', 'v21.0');
        $graphBase = 'https://graph.facebook.com/'.$graphVersion;
        $totalProcessed = 0;

        /** @var HomeController $homeController */
        $homeController = app(HomeController::class);

        foreach ($items as $item) {
            $this->info("Checking account #{$item->id} (@{$item->username}) — IG ID: {$item->instagram_id}");

            if (empty($item->instagram_id)) {
                $this->warn("Account #{$item->id} has no instagram_id set, skipping.");

                continue;
            }

            $token = $item->access_token;

            // 1. Fetch recent media for this Instagram Business account
            $mediaResponse = Http::timeout(15)->withToken($token)->get("{$graphBase}/{$item->instagram_id}/media", [
                'fields' => 'id,caption',
                'limit' => $mediaLimit,
            ]);

            // Attempt token refresh if expired
            if (! $mediaResponse->successful()) {
                if ($tokenService->isTokenExpiredError($mediaResponse->status(), $mediaResponse->json() ?? [])) {
                    $this->warn("Token expired for account #{$item->id}, attempting refresh...");
                    $refreshed = $tokenService->refreshInstagramItemToken($item);
                    if ($refreshed) {
                        $token = $refreshed;
                        $mediaResponse = Http::timeout(15)->withToken($token)->get("{$graphBase}/{$item->instagram_id}/media", [
                            'fields' => 'id,caption',
                            'limit' => $mediaLimit,
                        ]);
                    }
                }
            }

            if (! $mediaResponse->successful()) {
                $this->error("Failed to fetch media for account #{$item->id}: ".$mediaResponse->body());
                Log::channel('stack')->warning("[POLL_IG] Failed to fetch media for account #{$item->id}", [
                    'status' => $mediaResponse->status(),
                    'error' => $mediaResponse->json(),
                ]);

                continue;
            }

            $mediaList = $mediaResponse->json('data', []);
            $this->line('Found '.count($mediaList)." media posts for @{$item->username}");

            // 2. For each media post, fetch top-level comments
            foreach ($mediaList as $media) {
                $mediaId = (string) ($media['id'] ?? '');
                if (empty($mediaId)) {
                    continue;
                }

                $commentsResponse = Http::timeout(15)->withToken($token)->get("{$graphBase}/{$mediaId}/comments", [
                    'fields' => 'id,text,username,timestamp,from',
                    'limit' => 25,
                ]);

                if (! $commentsResponse->successful()) {
                    continue;
                }

                $comments = $commentsResponse->json('data', []);

                foreach ($comments as $comment) {
                    $commentId = (string) ($comment['id'] ?? '');
                    if (empty($commentId)) {
                        continue;
                    }

                    $cacheKey = "ig_comment_replied_{$commentId}";
                    if (Cache::has($cacheKey)) {
                        continue;
                    }

                    $commentText = trim((string) ($comment['text'] ?? ''));
                    $senderUsername = trim((string) (data_get($comment, 'from.username') ?: data_get($comment, 'username', '')));
                    $senderId = (string) data_get($comment, 'from.id', '');

                    // Ignore comments made by the page/account itself
                    if ($senderId === (string) $item->instagram_id
                        || $senderId === (string) $item->page_id
                        || (! empty($item->username) && strtolower($senderUsername) === strtolower((string) $item->username))) {
                        Cache::put($cacheKey, true, now()->addDays(7));

                        continue;
                    }

                    // Ignore comments older than 7 days (Meta rejects private DM replies after 7 days)
                    $timestamp = data_get($comment, 'timestamp');
                    if ($timestamp && Carbon::parse($timestamp)->diffInDays(now()) > 7) {
                        Cache::put($cacheKey, true, now()->addDays(7));

                        continue;
                    }

                    // If --skip-existing is set, just mark as cached and don't reply
                    if ($skipExisting) {
                        Cache::put($cacheKey, true, now()->addDays(7));
                        $this->line("Marked existing comment {$commentId} as processed (--skip-existing).");

                        continue;
                    }

                    $this->info("Processing new comment {$commentId} from @{$senderUsername} on media {$mediaId}: \"{$commentText}\"");

                    // Build uniform feed change payload for handleInstagramFeedChange
                    $webhookPayload = [
                        'entry' => [
                            [
                                'id' => $item->instagram_id,
                                'changes' => [
                                    [
                                        'field' => 'comments',
                                        'value' => [
                                            'id' => $commentId,
                                            'text' => $commentText,
                                            'media' => [
                                                'id' => $mediaId,
                                            ],
                                            'from' => [
                                                'id' => $senderId ?: ($senderUsername ?: 'ig_user_'.$commentId),
                                                'username' => $senderUsername,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];

                    try {
                        $jsonResponse = $homeController->handleInstagramFeedChange($webhookPayload);
                        $this->line('Result: '.json_encode($jsonResponse->getData(true), JSON_UNESCAPED_UNICODE));
                        $totalProcessed++;
                    } catch (\Throwable $e) {
                        $this->error("Error processing comment {$commentId}: ".$e->getMessage());
                        Log::channel('stack')->error("[POLL_IG] Exception handling comment {$commentId}: ".$e->getMessage());
                    }
                }
            }
        }

        $this->info("Polling complete. Total comments processed: {$totalProcessed}");

        return 0;
    }
}
