<?php

namespace App\Console\Commands;

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\TikTokAccount;
use App\Models\WhatsItem;
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
    protected $description = 'Trigger n8n scraping workflow for channel accounts with credentials and without api_link';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
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

        foreach ($channels as $key => $config) {
            $channelName = $config['name'];
            /** @var class-string<Model> $modelClass */
            $modelClass = $config['model'];

            // Condition: has login_link, user_name, password, products_link AND does NOT have api_link
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

            if ($idFilter) {
                $query->where('id', $idFilter);
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

                if ($isDryRun) {
                    $this->line("  [DRY-RUN] Would dispatch {$channelName} #{$item->id} to n8n (login: {$item->login_link}, products: {$item->products_link})");
                    $totalDispatched++;

                    continue;
                }

                $this->line("  [*] Dispatching {$channelName} #{$item->id} to n8n...");
                Log::info("[products:scrape-n8n] Dispatching to n8n for {$channelName} #{$item->id}.", [
                    'channel' => $channelName,
                    'account_id' => $item->id,
                    'login_link' => $item->login_link,
                    'products_link' => $item->products_link,
                ]);

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
                        $this->info("  [✓] {$channelName} #{$item->id}: Successfully dispatched to n8n.");
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
    }
}
