<?php

namespace App\Services;

use App\Models\EcommerceOrder;
use App\Models\WhatsItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EcommerceStoreService
{
    /**
     * Update the order status in the external store (WooCommerce or Shopify).
     *
     * @param  string  $action  'approved' or 'rejected'
     */
    public function updateOrderStatus(EcommerceOrder $order, string $action): bool
    {
        $whatsItem = $order->whatsItem;

        if (! $whatsItem) {
            Log::warning('EcommerceStoreService: No WhatsItem linked to order', ['order_id' => $order->id]);

            return false;
        }

        $platform = strtolower((string) ($order->platform ?: $whatsItem->ecommerce_platform ?: 'woocommerce'));

        return match ($platform) {
            'shopify' => $this->updateShopifyOrder($order, $action),
            'custom' => $this->updateCustomOrder($order, $action),
            default => $this->updateWooCommerceOrder($order, $action),
        };
    }

    /**
     * Update WooCommerce order status via REST API.
     */
    protected function updateWooCommerceOrder(EcommerceOrder $order, string $action): bool
    {
        $whatsItem = $order->whatsItem;
        $baseUrl = $whatsItem->website_url ?: $whatsItem->api_link;

        if (empty($baseUrl)) {
            Log::warning('EcommerceStoreService: WooCommerce store URL is missing', [
                'whats_item_id' => $whatsItem->id,
                'order_id' => $order->id,
            ]);

            return false;
        }

        $baseUrl = rtrim($baseUrl, '/');
        if (! str_starts_with($baseUrl, 'http://') && ! str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://'.$baseUrl;
        }

        $consumerKey = (string) $whatsItem->user_name;
        $consumerSecret = (string) $whatsItem->password;

        if (empty($consumerKey) || empty($consumerSecret)) {
            Log::warning('EcommerceStoreService: WooCommerce credentials (user_name/password) missing', [
                'whats_item_id' => $whatsItem->id,
                'order_id' => $order->id,
            ]);

            return false;
        }

        $wcStatus = $action === 'approved' ? 'processing' : 'cancelled';

        $selfLink = data_get($order->raw_payload, '_links.self.0.href');
        $endpoint = ! empty($selfLink)
            ? (string) $selfLink
            : "{$baseUrl}/wp-json/wc/v3/orders/{$order->external_order_id}";

        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $finalUrl = "{$endpoint}{$separator}consumer_key={$consumerKey}&consumer_secret={$consumerSecret}";

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->put($finalUrl, [
                    'status' => $wcStatus,
                ]);

            if ($response->status() === 404 && ! str_contains($endpoint, 'rest_route')) {
                $fallbackUrl = "{$baseUrl}/index.php?rest_route=/wc/v3/orders/{$order->external_order_id}&consumer_key={$consumerKey}&consumer_secret={$consumerSecret}";
                $fallbackResponse = Http::withBasicAuth($consumerKey, $consumerSecret)
                    ->timeout(15)
                    ->put($fallbackUrl, [
                        'status' => $wcStatus,
                    ]);

                if ($fallbackResponse->successful()) {
                    $response = $fallbackResponse;
                }
            }

            if ($response->successful()) {
                Log::info("EcommerceStoreService: WooCommerce order #{$order->external_order_id} updated to [{$wcStatus}]", [
                    'order_id' => $order->id,
                    'response_status' => $response->json('status'),
                ]);

                return true;
            }

            Log::error('EcommerceStoreService: Failed to update WooCommerce order', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'http_status' => $response->status(),
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('EcommerceStoreService: Exception updating WooCommerce order: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);

            return false;
        }
    }

    /**
     * Update custom external store via its configured confirm or reject API endpoints.
     * Passes the order id in query params and api_key in the headers.
     */
    protected function updateCustomOrder(EcommerceOrder $order, string $action): bool
    {
        $whatsItem = $order->whatsItem;

        if (! $whatsItem) {
            Log::warning('EcommerceStoreService: No WhatsItem linked to custom order', ['order_id' => $order->id]);

            return false;
        }

        $callbackUrl = $action === 'approved'
            ? $whatsItem->api_confirm_url
            : $whatsItem->api_reject_url;

        if (empty($callbackUrl)) {
            Log::warning("EcommerceStoreService: Custom store callback URL is not configured for [{$action}]", [
                'whats_item_id' => $whatsItem->id,
                'order_id' => $order->id,
                'action' => $action,
            ]);

            return false;
        }

        $apiKey = (string) ($whatsItem->custom_api_key ?: ($whatsItem->store_webhook_token ?: ''));
        $externalOrderId = (string) ($order->external_order_id ?: $order->id);

        // Append id to query parameters: (?id=...)
        $separator = str_contains($callbackUrl, '?') ? '&' : '?';
        $finalUrl = $callbackUrl.$separator.'id='.urlencode($externalOrderId);

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
            ])->timeout(15)->post($finalUrl);

            if ($response->successful()) {
                Log::info("EcommerceStoreService: Custom order #{$externalOrderId} updated to [{$action}] on [{$callbackUrl}]", [
                    'order_id' => $order->id,
                    'http_status' => $response->status(),
                ]);

                return true;
            }

            Log::error('EcommerceStoreService: Failed to update custom order on external API', [
                'order_id' => $order->id,
                'external_order_id' => $externalOrderId,
                'http_status' => $response->status(),
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('EcommerceStoreService: Exception updating custom order: '.$e->getMessage(), [
                'order_id' => $order->id,
                'external_order_id' => $externalOrderId,
                'url' => $finalUrl,
            ]);

            return false;
        }
    }

    /**
     * Update Shopify order status or cancel via Admin API.
     */
    protected function updateShopifyOrder(EcommerceOrder $order, string $action): bool
    {
        $whatsItem = $order->whatsItem;
        $domain = $whatsItem->website_url;

        if (empty($domain)) {
            Log::warning('EcommerceStoreService: Shopify shop domain is missing', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        $domain = preg_replace('#^https?://#', '', rtrim($domain, '/'));
        $accessToken = $this->resolveShopifyAccessToken($whatsItem, $domain);

        if (empty($accessToken)) {
            Log::warning('EcommerceStoreService: Shopify access token or client credentials missing', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        try {
            if ($action === 'rejected') {
                // Cancel order in Shopify
                $endpoint = "https://{$domain}/admin/api/2024-01/orders/{$order->external_order_id}/cancel.json";
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])->timeout(15)->post($endpoint, [
                    'reason' => 'customer',
                    'email' => false,
                ]);
            } else {
                // Tag order as Processing and WhatsApp-Confirmed
                $endpoint = "https://{$domain}/admin/api/2024-01/orders/{$order->external_order_id}.json";
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])->timeout(15)->put($endpoint, [
                    'order' => [
                        'id' => $order->external_order_id,
                        'tags' => 'Processing, WhatsApp-Confirmed',
                        'note' => 'تم تأكيد الطلب بنجاح عبر رسائل واتساب التفاعلية (جاهز للتجهيز - Processing).',
                    ],
                ]);
            }

            if ($response->successful()) {
                Log::info("EcommerceStoreService: Shopify order #{$order->external_order_id} processed for [{$action}]", [
                    'order_id' => $order->id,
                ]);

                return true;
            }

            Log::error('EcommerceStoreService: Failed to update Shopify order', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('EcommerceStoreService: Exception updating Shopify order: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);

            return false;
        }
    }

    /**
     * Resolve the Shopify Access Token using direct token or client_id/secret credentials.
     */
    public function resolveShopifyAccessToken(WhatsItem $whatsItem, string $domain): ?string
    {
        // 1. Direct saved access token
        if (! empty($whatsItem->shopify_access_token)) {
            return $whatsItem->shopify_access_token;
        }

        $secretOrToken = (string) $whatsItem->password;
        $clientId = (string) $whatsItem->user_name;

        // 2. If token starts with shpat_ or shpca_, it's directly an Admin API Access Token
        if (str_starts_with($secretOrToken, 'shpat_') || str_starts_with($secretOrToken, 'shpca_')) {
            return $secretOrToken;
        }

        // 3. If client_id and client_secret are provided, exchange via Client Credentials
        if (! empty($clientId) && ! empty($secretOrToken)) {
            try {
                $tokenUrl = "https://{$domain}/admin/oauth/access_token";
                $response = Http::timeout(10)->post($tokenUrl, [
                    'client_id' => $clientId,
                    'client_secret' => $secretOrToken,
                    'grant_type' => 'client_credentials',
                ]);

                if ($response->successful() && $response->json('access_token')) {
                    $token = $response->json('access_token');
                    $whatsItem->update(['shopify_access_token' => $token]);

                    return $token;
                }
            } catch (\Throwable $e) {
                Log::warning('EcommerceStoreService: Failed to exchange Shopify client_id/secret: '.$e->getMessage());
            }
        }

        return ! empty($secretOrToken) ? $secretOrToken : null;
    }

    /**
     * Test and connect a Shopify store by fetching or validating its access token.
     *
     * @return array{status: bool, message: string, access_token?: ?string, shop?: ?array, error?: ?string}
     */
    public function connectShopify(WhatsItem $whatsItem, ?string $domain = null, ?string $clientId = null, ?string $secret = null): array
    {
        $domain = $domain ?: $whatsItem->website_url;
        $clientId = $clientId ?: $whatsItem->user_name;
        $secret = $secret ?: $whatsItem->password;

        if (empty($domain)) {
            return [
                'status' => false,
                'message' => 'Shopify store URL/domain is required (e.g. mystore.myshopify.com).',
            ];
        }

        $cleanDomain = preg_replace('#^https?://#', '', rtrim($domain, '/'));

        if (empty($secret)) {
            return [
                'status' => false,
                'message' => 'Shopify secret or access token is required.',
            ];
        }

        // 1. If secret is already an Admin API Access Token or needs exchange
        $token = $secret;
        if (! str_starts_with($secret, 'shpat_') && ! str_starts_with($secret, 'shpca_') && ! empty($clientId)) {
            try {
                $response = Http::timeout(10)->post("https://{$cleanDomain}/admin/oauth/access_token", [
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                    'grant_type' => 'client_credentials',
                ]);

                if ($response->successful() && $response->json('access_token')) {
                    $token = $response->json('access_token');
                }
            } catch (\Throwable $e) {
                Log::warning('EcommerceStoreService: Token exchange error: '.$e->getMessage());
            }
        }

        // 2. Validate token against Shopify shop endpoint
        try {
            $shopResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
            ])->timeout(10)->get("https://{$cleanDomain}/admin/api/2024-01/shop.json");

            if ($shopResponse->successful()) {
                $shopData = $shopResponse->json('shop', []);
                $whatsItem->update([
                    'website_url' => $cleanDomain,
                    'user_name' => $clientId,
                    'password' => $secret,
                    'shopify_access_token' => $token,
                    'ecommerce_platform' => 'shopify',
                ]);

                return [
                    'status' => true,
                    'message' => 'Shopify store connected successfully.',
                    'access_token' => $token,
                    'shop' => [
                        'name' => $shopData['name'] ?? null,
                        'email' => $shopData['email'] ?? null,
                        'domain' => $shopData['domain'] ?? $cleanDomain,
                        'currency' => $shopData['currency'] ?? 'EGP',
                    ],
                ];
            }

            $isAppSecret = str_starts_with((string) $secret, 'shpss_');
            $customMsg = $isAppSecret
                ? 'الرمز المدخل (shpss_...) هو Client Secret الخاص بالتطبيق وليس Access Token. يتطلب Shopify إدخال رمز Admin API access token (الذي يبدأ بـ shpat_). يرجى الضغط على زر Install app ثم Reveal token once داخل Shopify Admin > Develop apps > API credentials.'
                : 'Failed to connect to Shopify. Please verify your domain and Admin API Access Token (shpat_...).';

            return [
                'status' => false,
                'message' => $customMsg,
                'error' => $shopResponse->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Connection to Shopify failed: '.$e->getMessage(),
            ];
        }
    }
}
