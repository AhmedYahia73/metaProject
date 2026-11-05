<?php

namespace App\trait;

use App\Console\Commands\ScrapeChannelProductsN8nCommand;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\TikTokAccount;
use App\Models\WhatsItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait SyncsChannelProducts
{
    /**
     * Prevent recursion during products:scrape-n8n execution.
     */
    protected static bool $isScrapingChannelProducts = false;

    /**
     * Flag to enable auto-scraping products_link during unit tests.
     */
    public static bool $enableScrapeOnSaveInTests = false;

    /**
     * Boot the SyncsChannelProducts trait for the model.
     * Automatically triggers products sync when api_link or products_link is created or updated.
     */
    public static function bootSyncsChannelProducts(): void
    {
        static::saving(function (Model $model) {
            $isApiLinkDirty = $model->isDirty('api_link');
            $hasApiLink = ! empty($model->api_link);
            $hasNoUserData = empty($model->user_data);

            if (! $isApiLinkDirty && ! ($hasApiLink && $hasNoUserData)) {
                return;
            }

            $rawLink = $model->api_link;

            // If api_link is empty: only clear user_data if api_link was previously non-empty and is now being cleared
            if (empty($rawLink)) {
                if ($model->exists && ! empty($model->getOriginal('api_link'))) {
                    $model->user_data = null;
                    Log::info('[api_link:sync] api_link cleared; user_data set to null.', [
                        'model' => class_basename($model),
                        'id' => $model->id ?? null,
                    ]);
                }

                return;
            }

            $modelName = class_basename($model);
            $id = $model->id ?? 'new';

            Log::info("[api_link:sync] api_link changed for {$modelName} #{$id}. Fetching products...", [
                'model' => $modelName,
                'id' => $id,
                'api_link' => $rawLink,
            ]);

            $data = static::fetchProductsPayloadFromApiLink($rawLink);

            if (is_array($data)) {
                $model->user_data = $data;

                $itemsList = $data['data']['data'] ?? $data['data'] ?? $data['products'] ?? (array_is_list($data) ? $data : []);
                $count = is_array($itemsList) ? count($itemsList) : 0;

                Log::info("[api_link:sync] SUCCESS: Synced {$count} product(s) to user_data on save for {$modelName} #{$id}.", [
                    'model' => $modelName,
                    'id' => $id,
                    'products_count' => $count,
                ]);
            } else {
                Log::warning("[api_link:sync] FAILED: Could not fetch products on save for {$modelName} #{$id}.", [
                    'model' => $modelName,
                    'id' => $id,
                    'api_link' => $rawLink,
                ]);
            }
        });

        static::created(function (Model $model) {
            $link = $model->products_link ?: ($model->product_link ?? null);
            if (! empty($link)) {
                static::triggerProductsScrapeIfNeeded($model, 'create');
            }
        });

        static::updated(function (Model $model) {
            $linkChanged = $model->wasChanged('products_link') || $model->wasChanged('product_link');
            $hasLink = ! empty($model->products_link) || ! empty($model->product_link);
            $hasNoUserData = empty($model->user_data);

            if ($linkChanged || ($hasLink && $hasNoUserData)) {
                static::triggerProductsScrapeIfNeeded($model, 'update');
            }
        });
    }

    /**
     * Mutator to allow setting products_link via product_link (singular).
     */
    public function setProductLinkAttribute($value): void
    {
        $this->attributes['products_link'] = $value;
    }

    /**
     * Accessor to allow reading product_link (singular).
     */
    public function getProductLinkAttribute(): ?string
    {
        return $this->attributes['products_link'] ?? null;
    }

    /**
     * Trigger products:scrape-n8n command for the model if conditions are met.
     */
    protected static function triggerProductsScrapeIfNeeded(Model $model, string $action): void
    {
        if (ScrapeChannelProductsN8nCommand::$isScraping) {
            return;
        }

        if (app()->runningUnitTests() && ! config('services.products_scrape.enable_in_tests', false)) {
            return;
        }

        $channelKey = static::resolveChannelKeyForModel($model);
        if (! $channelKey || ! $model->id) {
            return;
        }

        $productsLink = $model->products_link ?: ($model->product_link ?? null);
        if (empty($productsLink)) {
            return;
        }

        try {
            Log::info("[products_link:scrape] Triggering products:scrape-n8n for {$channelKey} #{$model->id} on {$action}.", [
                'channel' => $channelKey,
                'id' => $model->id,
                'products_link' => $productsLink,
            ]);

            Artisan::call('products:scrape-n8n', [
                '--channel' => $channelKey,
                '--id' => (string) $model->id,
            ]);

            Log::info("[products_link:scrape] Completed products:scrape-n8n for {$channelKey} #{$model->id}.");
        } catch (\Throwable $e) {
            Log::error("[products_link:scrape] Error running products:scrape-n8n for {$channelKey} #{$model->id}: ".$e->getMessage());
        }
    }

    /**
     * Resolve the channel key expected by products:scrape-n8n for a given model.
     */
    public static function resolveChannelKeyForModel(Model $model): ?string
    {
        return match ($model::class) {
            InstagramItem::class => 'instagram',
            MessengerAccount::class => 'messenger',
            TelegramAccount::class => 'telegram',
            TikTokAccount::class => 'tiktok',
            WhatsItem::class => 'whats',
            default => null,
        };
    }

    /**
     * Helper to resolve the products URL and fetch the raw JSON payload.
     */
    public static function fetchProductsPayloadFromApiLink(string $apiLink): ?array
    {
        $url = static::resolveProductsApiUrlString($apiLink);
        if (empty($url)) {
            return null;
        }

        if (app()->runningUnitTests()) {
            $hasFakes = \Closure::bind(
                fn () => ! empty($this->stubCallbacks) && $this->stubCallbacks->isNotEmpty(),
                app(Factory::class),
                Factory::class
            )();

            if (! $hasFakes) {
                return null;
            }
        }

        try {
            $response = Http::timeout(15)
                ->withoutVerifying()
                ->retry(2, 200, throw: false)
                ->get($url);

            if ($response->successful()) {
                $json = $response->json();

                return is_array($json) ? $json : null;
            }

            Log::error("[api_link:sync] HTTP returned non-200 [{$response->status()}] for URL [{$url}].");
        } catch (\Throwable $e) {
            Log::error("[api_link:sync] Exception fetching products from [{$url}]: ".$e->getMessage());
        }

        return null;
    }

    /**
     * Normalize and resolve products endpoint URL.
     */
    public static function resolveProductsApiUrlString(string $apiLink): ?string
    {
        $trimmed = trim($apiLink);
        $trimmed = preg_replace('/^api[:\s]+/i', '', $trimmed);
        $trimmed = trim($trimmed, "<>\"' \t\n\r\0\x0B");

        if (empty($trimmed)) {
            return null;
        }

        if (! preg_match('~^https?://~i', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        $parsed = parse_url($trimmed);
        $path = rtrim($parsed['path'] ?? '', '/');

        if (str_ends_with($path, '/api/public/products') || str_ends_with($path, '/public/products')) {
            return $trimmed;
        } elseif (str_ends_with($path, '/api')) {
            return rtrim($trimmed, '/').'/public/products';
        }

        return rtrim($trimmed, '/').'/api/public/products';
    }
}
