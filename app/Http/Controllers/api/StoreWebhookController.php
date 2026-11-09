<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrder;
use App\Models\WhatsItem;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class StoreWebhookController extends Controller
{
    public function __construct(
        protected MetaWhatsAppService $metaService
    ) {}

    /**
     * Handle incoming webhook for new orders from WooCommerce, Shopify, or Custom Platforms with token in URL.
     *
     * URL: POST /api/webhooks/store/{token}
     */
    public function handle(Request $request, string $token): JsonResponse
    {
        Log::info("StoreWebhook: Received callback for token [{$token}]", [
            'headers' => $request->headers->all(),
            'ip' => $request->ip(),
        ]);

        $whatsItem = $this->resolveWhatsItem($token);

        if (! $whatsItem) {
            Log::warning("StoreWebhook: Invalid token provided: {$token}");

            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook token or store not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->processOrderWebhook($request, $whatsItem);
    }

    /**
     * Handle incoming webhook for Custom Platforms authenticated via Header or Body API Key.
     *
     * URL: POST /api/webhooks/custom/order
     */
    public function handleCustom(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-API-KEY')
            ?? $request->bearerToken()
            ?? (string) ($request->input('api_key') ?? $request->query('api_key') ?? '');

        if (empty($apiKey)) {
            return response()->json([
                'status' => false,
                'message' => 'Missing API key in header (X-API-KEY) or Bearer token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $whatsItem = $this->resolveWhatsItem($apiKey);

        if (! $whatsItem) {
            Log::warning("StoreWebhook: Invalid API key provided for custom order: {$apiKey}");

            return response()->json([
                'status' => false,
                'message' => 'Invalid API key or WhatsApp channel not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->processOrderWebhook($request, $whatsItem);
    }

    /**
     * Resolve WhatsItem by store_webhook_token, custom_api_key, or Sanctum Personal Access Token.
     */
    protected function resolveWhatsItem(string $tokenOrKey): ?WhatsItem
    {
        $whatsItem = WhatsItem::where('store_webhook_token', $tokenOrKey)
            ->orWhere('custom_api_key', $tokenOrKey)
            ->first();

        if ($whatsItem) {
            return $whatsItem;
        }

        // Support Sanctum Personal Access Token (e.g. "217|8g447Rym...")
        if (str_contains($tokenOrKey, '|')) {
            $personalAccessToken = PersonalAccessToken::findToken($tokenOrKey);
            if ($personalAccessToken && $personalAccessToken->tokenable) {
                return WhatsItem::where('user_id', $personalAccessToken->tokenable_id)
                    ->where('order_confirmation_enabled', true)
                    ->latest()
                    ->first()
                    ?? WhatsItem::where('user_id', $personalAccessToken->tokenable_id)->latest()->first();
            }
        }

        return null;
    }

    /**
     * Process validated webhook order data and send WhatsApp notification.
     */
    protected function processOrderWebhook(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        // 1. Check if order confirmations are enabled for this WhatsApp number
        if (! $whatsItem->order_confirmation_enabled) {
            Log::info("StoreWebhook: Order confirmation is disabled for WhatsItem #{$whatsItem->id}");

            return response()->json([
                'status' => 'ignored',
                'message' => 'Order confirmation feature is disabled for this WhatsApp number.',
            ], Response::HTTP_OK);
        }

        // 2. Check if account has active subscription with remaining messages
        if (! $whatsItem->hasActiveSubscription()) {
            Log::warning("StoreWebhook: WhatsItem #{$whatsItem->id} does not have an active subscription or messages are depleted.");

            return response()->json([
                'status' => 'limit_exceeded',
                'message' => 'No active subscription or message quota depleted.',
            ], Response::HTTP_OK);
        }

        // 3. Detect platform (WooCommerce vs Shopify vs Custom)
        $platform = $this->detectPlatform($request, $whatsItem);

        // 4. Extract order details based on platform
        $orderData = match ($platform) {
            'shopify' => $this->parseShopifyPayload($request),
            'custom' => $this->parseCustomPayload($request),
            default => $this->parseWooCommercePayload($request),
        };

        if (empty($orderData['external_order_id'])) {
            Log::warning('StoreWebhook: Missing order ID in payload', ['payload' => $request->all()]);

            return response()->json([
                'status' => false,
                'message' => 'Could not determine external order ID from payload (field: id or order_id).',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($orderData['customer_phone'])) {
            Log::warning("StoreWebhook: Order #{$orderData['external_order_id']} does not contain customer phone number.");

            return response()->json([
                'status' => 'no_phone',
                'message' => 'Order does not have a customer phone number.',
            ], Response::HTTP_OK);
        }

        // 5. Ensure template is registered on Meta WABA
        $templateResult = $this->metaService->ensureOrderConfirmationTemplate($whatsItem);

        // 6. Store order (idempotent: prevent duplicate sending if webhook retries)
        /** @var EcommerceOrder $order */
        $order = EcommerceOrder::firstOrCreate(
            [
                'whats_item_id' => $whatsItem->id,
                'external_order_id' => (string) $orderData['external_order_id'],
            ],
            [
                'user_id' => $whatsItem->user_id,
                'platform' => $platform,
                'order_number' => (string) $orderData['order_number'],
                'customer_name' => $orderData['customer_name'],
                'customer_phone' => $orderData['customer_phone'],
                'total_price' => $orderData['total_price'],
                'currency' => $orderData['currency'],
                'status' => 'pending',
                'raw_payload' => $request->all(),
            ]
        );

        // 7. Send WhatsApp template if not already sent
        $sentResult = null;
        if (empty($order->meta_message_id)) {
            $sentResult = $this->metaService->sendOrderConfirmationMessage(
                $whatsItem,
                $orderData['customer_phone'],
                $order,
                $whatsItem->user?->restuarant_name ?: $whatsItem->user?->name
            );
        }

        return response()->json([
            'status' => true,
            'message' => 'Order processed successfully.',
            'order_id' => $order->id,
            'template_result' => $templateResult,
            'whatsapp_result' => $sentResult,
        ], Response::HTTP_OK);
    }

    /**
     * Detect whether the incoming webhook is from WooCommerce, Shopify, or Custom.
     */
    protected function detectPlatform(Request $request, WhatsItem $whatsItem): string
    {
        if ($request->hasHeader('X-Shopify-Topic') || $request->hasHeader('X-Shopify-Hmac-Sha256') || $request->hasHeader('X-Shopify-Shop-Domain')) {
            return 'shopify';
        }

        if ($request->hasHeader('X-WC-Webhook-Topic') || $request->hasHeader('X-WC-Webhook-Source')) {
            return 'woocommerce';
        }

        if ($whatsItem->ecommerce_platform === 'custom' || $request->hasHeader('X-API-KEY') || $request->filled('custom')) {
            return 'custom';
        }

        return strtolower((string) ($whatsItem->ecommerce_platform ?: 'woocommerce'));
    }

    /**
     * Extract order details from WooCommerce webhook payload.
     *
     * @return array{external_order_id: ?string, order_number: ?string, customer_name: ?string, customer_phone: ?string, total_price: float, currency: string}
     */
    protected function parseWooCommercePayload(Request $request): array
    {
        $id = $request->input('id');
        $number = $request->input('number') ?? $id;

        $firstName = (string) ($request->input('billing.first_name') ?? $request->input('shipping.first_name') ?? '');
        $lastName = (string) ($request->input('billing.last_name') ?? $request->input('shipping.last_name') ?? '');
        $customerName = trim("{$firstName} {$lastName}");

        $phone = $request->input('billing.phone')
            ?? $request->input('shipping.phone')
            ?? $request->input('customer.phone')
            ?? '';

        $total = (float) ($request->input('total') ?? $request->input('total_amount') ?? 0);
        $currency = (string) ($request->input('currency') ?? 'EGP');

        return [
            'external_order_id' => $id ? (string) $id : null,
            'order_number' => $number ? (string) $number : null,
            'customer_name' => $customerName ?: null,
            'customer_phone' => (string) $phone,
            'total_price' => $total,
            'currency' => $currency,
        ];
    }

    /**
     * Extract order details from Shopify webhook payload with comprehensive fallbacks for Guest Checkout.
     *
     * @return array{external_order_id: ?string, order_number: ?string, customer_name: ?string, customer_phone: ?string, total_price: float, currency: string}
     */
    protected function parseShopifyPayload(Request $request): array
    {
        $id = $request->input('id');
        $orderNumber = $request->input('order_number') ?? $request->input('name') ?? $id;

        // Try customer first, fallback to shipping_address, billing_address, default_address
        $firstName = (string) (
            $request->input('customer.first_name')
            ?? $request->input('shipping_address.first_name')
            ?? $request->input('billing_address.first_name')
            ?? $request->input('customer.default_address.first_name')
            ?? ''
        );

        $lastName = (string) (
            $request->input('customer.last_name')
            ?? $request->input('shipping_address.last_name')
            ?? $request->input('billing_address.last_name')
            ?? $request->input('customer.default_address.last_name')
            ?? ''
        );

        $customerName = trim("{$firstName} {$lastName}");

        // If still empty, check full name field directly
        if (empty($customerName)) {
            $customerName = (string) (
                $request->input('shipping_address.name')
                ?? $request->input('billing_address.name')
                ?? $request->input('customer.name')
                ?? ''
            );
        }

        // Phone number fallback across all possible Shopify locations
        $phone = $request->input('customer.phone')
            ?? $request->input('shipping_address.phone')
            ?? $request->input('billing_address.phone')
            ?? $request->input('customer.default_address.phone')
            ?? $request->input('phone')
            ?? '';

        $total = (float) (
            $request->input('current_total_price')
            ?? $request->input('total_price')
            ?? $request->input('total_price_set.shop_money.amount')
            ?? 0
        );

        $currency = (string) (
            $request->input('currency')
            ?? $request->input('presentment_currency')
            ?? 'EGP'
        );

        return [
            'external_order_id' => $id ? (string) $id : null,
            'order_number' => $orderNumber ? (string) $orderNumber : null,
            'customer_name' => $customerName ?: null,
            'customer_phone' => (string) $phone,
            'total_price' => $total,
            'currency' => $currency,
        ];
    }

    /**
     * Extract order details for Custom External Platforms using the 5 standard variables.
     *
     * Standard Variables:
     * 1. id (order ID)
     * 2. customer_name
     * 3. customer_phone
     * 4. total_price
     * 5. currency
     *
     * @return array{external_order_id: ?string, order_number: ?string, customer_name: ?string, customer_phone: ?string, total_price: float, currency: string}
     */
    protected function parseCustomPayload(Request $request): array
    {
        $id = $request->input('id') ?? $request->input('order_id');
        $orderNumber = $request->input('order_number') ?? $id;

        $customerName = trim((string) (
            $request->input('customer_name')
            ?? $request->input('name')
            ?? ''
        ));

        $customerPhone = (string) (
            $request->input('customer_phone')
            ?? $request->input('phone')
            ?? ''
        );

        $totalPrice = (float) (
            $request->input('total_price')
            ?? $request->input('total')
            ?? $request->input('price')
            ?? 0
        );

        $currency = (string) (
            $request->input('currency')
            ?? 'EGP'
        );

        return [
            'external_order_id' => $id ? (string) $id : null,
            'order_number' => $orderNumber ? (string) $orderNumber : null,
            'customer_name' => $customerName ?: null,
            'customer_phone' => $customerPhone,
            'total_price' => $totalPrice,
            'currency' => $currency,
        ];
    }
}
