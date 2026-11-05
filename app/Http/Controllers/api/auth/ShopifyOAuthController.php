<?php

namespace App\Http\Controllers\api\auth;

use App\Http\Controllers\Controller;
use App\Models\WhatsItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyOAuthController extends Controller
{
    /**
     * Start the Shopify OAuth installation flow.
     *
     * URL: GET /api/auth/shopify/install?whats_item_id=15&shop=g0dc5e-db.myshopify.com
     */
    public function install(Request $request): RedirectResponse|JsonResponse
    {
        $whatsItemId = $request->input('whats_item_id');
        $whatsItem = WhatsItem::find($whatsItemId);

        $shop = (string) ($request->input('shop') ?: ($whatsItem?->website_url ?: ''));
        if (empty($shop)) {
            return response()->json([
                'status' => false,
                'message' => 'Please provide the shop domain (e.g. your-store.myshopify.com) or whats_item_id with website_url configured.',
            ], 422);
        }

        $cleanShop = preg_replace('#^https?://#', '', rtrim($shop, '/'));
        if (! str_contains($cleanShop, '.')) {
            $cleanShop .= '.myshopify.com';
        }

        $clientId = (string) ($whatsItem?->user_name ?: config('services.shopify.client_id', ''));
        $scopes = 'read_orders,write_orders,read_products,read_customers';
        $redirectUri = url('/api/auth/shopify/callback');
        $state = (string) $whatsItemId;

        $authUrl = "https://{$cleanShop}/admin/oauth/authorize?".http_build_query([
            'client_id' => $clientId,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return redirect()->away($authUrl);
    }

    /**
     * Handle Shopify OAuth callback, exchange code for access_token and save it.
     *
     * URL: GET /api/auth/shopify/callback
     */
    public function callback(Request $request): Response|JsonResponse
    {
        $code = (string) $request->input('code');
        $shop = (string) $request->input('shop');
        $state = (string) $request->input('state');
        $hmac = (string) $request->input('hmac');

        if (empty($code) || empty($shop)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OAuth callback: missing code or shop parameter.',
            ], 400);
        }

        $cleanShop = preg_replace('#^https?://#', '', rtrim($shop, '/'));

        // 1. Resolve WhatsItem
        $whatsItem = null;
        if (! empty($state) && is_numeric($state)) {
            $whatsItem = WhatsItem::find($state);
        }

        if (! $whatsItem) {
            $whatsItem = WhatsItem::where('website_url', 'like', "%{$cleanShop}%")->first();
        }

        $clientId = (string) ($whatsItem?->user_name ?: config('services.shopify.client_id', ''));
        $clientSecret = (string) ($whatsItem?->password ?: config('services.shopify.client_secret', ''));

        // 2. Exchange code for permanent access token
        $tokenUrl = "https://{$cleanShop}/admin/oauth/access_token";

        try {
            $response = Http::timeout(15)->post($tokenUrl, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
            ]);

            if ($response->successful() && $response->json('access_token')) {
                $accessToken = (string) $response->json('access_token');

                if ($whatsItem) {
                    $whatsItem->update([
                        'website_url' => $cleanShop,
                        'user_name' => $clientId,
                        'password' => $clientSecret,
                        'shopify_access_token' => $accessToken,
                        'ecommerce_platform' => 'shopify',
                        'order_confirmation_enabled' => true,
                    ]);

                    Log::info("ShopifyOAuth: Successfully connected shop [{$cleanShop}] for WhatsItem #{$whatsItem->id}");
                }

                // Render friendly HTML success page
                $html = <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تم ربط متجر Shopify بنجاح</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f8; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); text-align: center; max-width: 480px; width: 90%; }
        .icon { font-size: 54px; margin-bottom: 16px; }
        h1 { color: #202223; font-size: 24px; margin-bottom: 12px; }
        p { color: #6d7175; font-size: 16px; line-height: 1.5; margin-bottom: 24px; }
        .token { background: #f1f2f3; padding: 10px 14px; border-radius: 8px; font-family: monospace; font-size: 14px; word-break: break-all; color: #2c6ecb; margin-bottom: 24px; }
        .btn { background: #008060; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-block; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🎉</div>
        <h1>تم ربط متجر Shopify بنجاح!</h1>
        <p>تم تثبيت تطبيق Smartego وتوليد الـ Access Token الخاص بمتجرك (<strong>{$cleanShop}</strong>) بنجاح.</p>
        <div class="token">Token: {$accessToken}</div>
        <p>يمكنك الآن إغلاق هذه الصفحة والعودة للوحة التحكم، واستقبال أوردرات المتجر عبر واتساب!</p>
    </div>
</body>
</html>
HTML;

                return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
            }

            Log::error('ShopifyOAuth: Failed to exchange access token', [
                'shop' => $cleanShop,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to exchange authorization code for access token.',
                'error' => $response->json() ?? $response->body(),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('ShopifyOAuth: Exception in callback: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Shopify OAuth exception: '.$e->getMessage(),
            ], 500);
        }
    }
}
