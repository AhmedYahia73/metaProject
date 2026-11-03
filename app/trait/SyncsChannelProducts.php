<?php

namespace App\trait;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait SyncsChannelProducts
{
    /**
     * Boot the SyncsChannelProducts trait for the model.
     * Automatically triggers products sync when api_link is created or updated.
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
