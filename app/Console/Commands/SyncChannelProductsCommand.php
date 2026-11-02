<?php

namespace App\Console\Commands;

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\TikTokAccount;
use App\Models\WhatsItem;
use App\trait\FormatsChatLinks;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncChannelProductsCommand extends Command
{
    use FormatsChatLinks;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:sync
                            {--channel= : Optional channel filter: instagram, messenger, telegram, tiktok, whats}
                            {--id= : Optional record ID to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch public products from each channel api_link and sync into user_data column';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $channelFilter = strtolower(trim((string) $this->option('channel')));
        $idFilter = $this->option('id');

        $this->info('====================================================');
        $this->info('Starting Channel Products Sync (api_link -> user_data)');
        $this->info('====================================================');

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

        $totalChecked = 0;
        $totalSuccess = 0;
        $totalFailed = 0;

        foreach ($channels as $key => $config) {
            $channelName = $config['name'];
            /** @var class-string<Model> $modelClass */
            $modelClass = $config['model'];

            $query = $modelClass::query()
                ->whereNotNull('api_link')
                ->where('api_link', '!=', '');

            if ($idFilter) {
                $query->where('id', $idFilter);
            }

            $items = $query->get();

            if ($items->isEmpty()) {
                $this->line("<fg=gray>[i] {$channelName}: No records with api_link found.</>");

                continue;
            }

            $this->comment("--> Checking {$channelName} ({$items->count()} accounts)...");

            foreach ($items as $item) {
                $totalChecked++;
                $url = $this->resolveProductsApiUrl($item->api_link);

                if (empty($url)) {
                    $this->warn("  [!] {$channelName} #{$item->id}: Invalid api_link [{$item->api_link}]");
                    $totalFailed++;

                    continue;
                }

                $this->line("  [*] Syncing {$channelName} #{$item->id} from {$url}...");

                try {
                    $response = Http::timeout(10)
                        ->withOptions([
                            'curl' => [
                                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                            ],
                        ])
                        ->retry(2, 200, throw: false)
                        ->get($url);

                    if ($response->successful()) {
                        $payload = $response->json();

                        if (! is_array($payload)) {
                            $this->error("  [✗] {$channelName} #{$item->id}: Response was not valid JSON array.");
                            $totalFailed++;

                            continue;
                        }

                        $item->update(['user_data' => $payload]);

                        $itemsList = $payload['data']['data'] ?? $payload['data'] ?? $payload['products'] ?? (array_is_list($payload) ? $payload : []);
                        $count = is_array($itemsList) ? count($itemsList) : 0;

                        $this->info("  [✓] {$channelName} #{$item->id}: Successfully saved {$count} product(s) to user_data.");
                        $totalSuccess++;
                    } else {
                        $this->error("  [✗] {$channelName} #{$item->id}: HTTP request returned status [{$response->status()}]");
                        $totalFailed++;
                    }
                } catch (\Throwable $e) {
                    $this->error("  [✗] {$channelName} #{$item->id}: Exception - {$e->getMessage()}");
                    Log::error("Failed syncing channel products for {$channelName} #{$item->id}: ".$e->getMessage());
                    $totalFailed++;
                }
            }
        }

        $this->newLine();
        $this->info('====================================================');
        $this->info("Sync completed: {$totalChecked} checked | <fg=green>{$totalSuccess} succeeded</> | <fg=red>{$totalFailed} failed</>");
        $this->info('====================================================');

        return self::SUCCESS;
    }
}
