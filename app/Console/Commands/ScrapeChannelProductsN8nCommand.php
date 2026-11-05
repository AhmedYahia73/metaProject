<?php

namespace App\Console\Commands;

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\TikTokAccount;
use App\Models\WhatsItem;
use App\Services\ApiDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeChannelProductsN8nCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:scrape-n8n
                            {--channel= : Optional channel filter: instagram, messenger, telegram, tiktok, whats}
                            {--id= : Optional record ID to scrape}
                            {--dry-run : Only list accounts without sending to n8n}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape products from channel accounts via API discovery (with n8n fallback)';

    /**
     * Static flag to detect if scraping is currently active.
     */
    public static bool $isScraping = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        self::$isScraping = true;

        try {
            $channelFilter = strtolower(trim((string) $this->option('channel')));
            $idFilter = $this->option('id');
            $isDryRun = (bool) $this->option('dry-run');

            $this->info('====================================================');
            $this->info('Starting Channel Products Scraping Dispatch (n8n)');
            $this->info('====================================================');

            Log::info('[products:scrape-n8n] Command started.', [
                'channel_filter' => $channelFilter !== '' ? $channelFilter : 'all',
                'id_filter' => $idFilter,
                'dry_run' => $isDryRun,
            ]);

            $n8nUrl = (string) config('services.n8n.webhook_url');
            $n8nSecret = (string) config('services.n8n.webhook_secret');

            if (! $isDryRun && empty($n8nUrl)) {
                $this->error('Error: N8N_SCRAPE_WEBHOOK_URL is not configured in .env or config/services.php.');
                Log::error('[products:scrape-n8n] Missing N8N_SCRAPE_WEBHOOK_URL configuration.');

                return self::FAILURE;
            }

            $channels = [
                'instagram' => ['name' => 'Instagram', 'model' => InstagramItem::class],
                'messenger' => ['name' => 'Messenger', 'model' => MessengerAccount::class],
                'telegram' => ['name' => 'Telegram',  'model' => TelegramAccount::class],
                'tiktok' => ['name' => 'TikTok',    'model' => TikTokAccount::class],
                'whats' => ['name' => 'WhatsApp',  'model' => WhatsItem::class],
            ];

            if ($channelFilter !== '') {
                $requestedChannels = array_filter(array_map('trim', explode(',', $channelFilter)));
                $filtered = [];
                foreach ($channels as $key => $config) {
                    foreach ($requestedChannels as $requested) {
                        if ($key === $requested || str_contains($requested, $key) || str_contains($key, $requested)) {
                            $filtered[$key] = $config;
                            break;
                        }
                    }
                }

                if (empty($filtered)) {
                    $this->error("Unknown channel filter: [{$channelFilter}]. Available: instagram, messenger, telegram, tiktok, whats");

                    return self::FAILURE;
                }

                $channels = $filtered;
            }

            $baseUrl = config('app.url');
            if (empty($baseUrl) || str_contains($baseUrl, 'localhost')) {
                $baseUrl = 'https://bcknd.smartego.org';
            }
            $callbackUrl = rtrim((string) $baseUrl, '/').'/api/internal/channel-products/webhook';
            $totalChecked = 0;
            $totalDispatched = 0;
            $totalFailed = 0;

            $discoveryService = app(ApiDiscoveryService::class);

            foreach ($channels as $key => $config) {
                $channelName = $config['name'];
                /** @var class-string<Model> $modelClass */
                $modelClass = $config['model'];

                // When targeting a specific account by ID, scrape as long as it has a products_link.
                // During automated batch/cron runs, only scrape accounts that don't use api_link and have credentials.
                if ($idFilter) {
                    $query = $modelClass::query()
                        ->where('id', $idFilter)
                        ->whereNotNull('products_link')
                        ->where('products_link', '!=', '');
                } else {
                    $query = $modelClass::query()
                        ->where(function ($q) {
                            $q->whereNull('api_link')
                                ->orWhere('api_link', '');
                        })
                        ->whereNotNull('login_link')
                        ->where('login_link', '!=', '')
                        ->whereNotNull('products_link')
                        ->where('products_link', '!=', '')
                        ->whereNotNull('user_name')
                        ->where('user_name', '!=', '')
                        ->whereNotNull('password')
                        ->where('password', '!=', '');
                }

                $items = $query->get();

                if ($items->isEmpty()) {
                    $this->line("<fg=gray>[i] {$channelName}: No accounts needing scraping found.</>");
                    Log::info("[products:scrape-n8n] {$channelName}: No accounts found requiring scraping.");

                    continue;
                }

                $this->comment("--> Checking {$channelName} ({$items->count()} accounts)...");
                Log::info("[products:scrape-n8n] Processing {$channelName}.", ['count' => $items->count()]);

                foreach ($items as $item) {
                    $totalChecked++;

                    if ($isDryRun) {
                        $this->line("  [DRY-RUN] Would scrape {$channelName} #{$item->id} (login: {$item->login_link}, products: {$item->products_link})");
                        $totalDispatched++;

                        continue;
                    }

                    // ── PRIMARY: Try API Discovery (universal, works for any SPA) ──
                    $this->line("  [*] Trying API discovery for {$channelName} #{$item->id}...");
                    Log::info("[products:scrape-n8n] Attempting API discovery for {$channelName} #{$item->id}.", [
                        'channel' => $channelName,
                        'account_id' => $item->id,
                        'login_link' => $item->login_link,
                        'products_link' => $item->products_link,
                    ]);

                    $result = $discoveryService->discoverAndFetch(
                        $item->login_link,
                        $item->user_name,
                        $item->password,
                        $item->products_link,
                    );

                    if ($result !== null && ! empty($result['products'])) {
                        try {
                            $item->update(['user_data' => $result['products']]);
                            $isMulti = ! array_is_list($result['products']);
                            $count = $isMulti
                                ? array_sum(array_map('count', $result['products']))
                                : count($result['products']);
                            $endpointsCount = $isMulti ? count($result['products']) : 1;

                            $this->info("  [✓] {$channelName} #{$item->id}: API discovery succeeded ({$endpointsCount} endpoints, {$count} items).");
                            Log::info("[products:scrape-n8n] SUCCESS: API discovery for {$channelName} #{$item->id}.", [
                                'channel' => $channelName,
                                'account_id' => $item->id,
                                'products_count' => $count,
                                'endpoints_count' => $endpointsCount,
                                'source' => $result['meta']['source'] ?? 'api_discovery',
                                'base_url' => $result['meta']['base_url'] ?? null,
                                'sample_item' => $isMulti
                                    ? (reset($result['products'])[0] ?? null)
                                    : ($result['products'][0] ?? null),
                            ]);
                            $totalDispatched++;

                            continue;
                        } catch (\Throwable $e) {
                            Log::error("[products:scrape-n8n] DB error saving API discovery results for {$channelName} #{$item->id}.", [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // ── FALLBACK: Dispatch to n8n ──
                    if (empty($n8nUrl)) {
                        $this->error("  [✗] {$channelName} #{$item->id}: API discovery failed and no n8n URL configured.");
                        Log::warning("[products:scrape-n8n] API discovery failed and no n8n fallback for {$channelName} #{$item->id}.");
                        $totalFailed++;

                        continue;
                    }

                    $this->line("  [*] API discovery failed, falling back to n8n for {$channelName} #{$item->id}...");
                    Log::info("[products:scrape-n8n] Falling back to n8n for {$channelName} #{$item->id}.");

                    $payload = [
                        'channel' => $key,
                        'account_id' => $item->id,
                        'login_link' => $item->login_link,
                        'user_name' => $item->user_name,
                        'password' => $item->password,
                        'products_link' => $item->products_link,
                        'callback_url' => $callbackUrl,
                        'secret_token' => $n8nSecret,
                        'triggered_at' => now()->toIso8601String(),
                    ];

                    try {
                        $request = Http::timeout(15)
                            ->withOptions([
                                'curl' => [
                                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                                ],
                            ]);

                        if ($n8nSecret !== '') {
                            $request->withHeaders([
                                'X-Internal-Token' => $n8nSecret,
                                'X-N8N-Key' => $n8nSecret,
                            ]);
                        }

                        $response = $request->retry(2, 500, throw: false)->post($n8nUrl, $payload);

                        if ($response->successful()) {
                            $this->info("  [✓] {$channelName} #{$item->id}: Dispatched to n8n fallback.");
                            Log::info("[products:scrape-n8n] SUCCESS: Dispatched {$channelName} #{$item->id} to n8n.", [
                                'channel' => $channelName,
                                'account_id' => $item->id,
                                'status' => $response->status(),
                            ]);
                            $totalDispatched++;
                        } else {
                            $this->error("  [✗] {$channelName} #{$item->id}: n8n returned HTTP status [{$response->status()}].");
                            Log::error("[products:scrape-n8n] FAILED: n8n webhook returned {$response->status()} for {$channelName} #{$item->id}.", [
                                'channel' => $channelName,
                                'account_id' => $item->id,
                                'response_body' => substr((string) $response->body(), 0, 300),
                            ]);
                            $totalFailed++;
                        }
                    } catch (\Throwable $e) {
                        $this->error("  [✗] {$channelName} #{$item->id}: Exception - {$e->getMessage()}");
                        Log::error("[products:scrape-n8n] Exception dispatching {$channelName} #{$item->id} to n8n.", [
                            'channel' => $channelName,
                            'account_id' => $item->id,
                            'error' => $e->getMessage(),
                        ]);
                        $totalFailed++;
                    }
                }
            }

            $this->newLine();
            $this->info('====================================================');
            $this->info("Scrape dispatch completed: {$totalChecked} checked | <fg=green>{$totalDispatched} dispatched</> | <fg=red>{$totalFailed} failed</>");
            $this->info('====================================================');

            Log::info('[products:scrape-n8n] Command finished.', [
                'total_checked' => $totalChecked,
                'total_dispatched' => $totalDispatched,
                'total_failed' => $totalFailed,
            ]);

            return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            self::$isScraping = false;
        }
    }
}
