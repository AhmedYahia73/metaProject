<?php

namespace App\Services;

use App\Models\EcommerceOrder;
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
        $endpoint = "{$baseUrl}/wp-json/wc/v3/orders/{$order->external_order_id}";

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->put($endpoint, [
                    'status' => $wcStatus,
                ]);

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
                // Tag or note order as WhatsApp Confirmed
                $endpoint = "https://{$domain}/admin/api/2024-01/orders/{$order->external_order_id}.json";
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])->timeout(15)->put($endpoint, [
                    'order' => [
                        'id' => $order->external_order_id,
                        'tags' => 'WhatsApp-Confirmed',
                        'note' => 'تم تأكيد الطلب بنجاح عبر رسائل واتساب التفاعلية.',
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
    protected function resolveShopifyAccessToken(WhatsItem $whatsItem, string $domain): ?string
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
}
