<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrder;
use App\Models\WhatsItem;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class StoreWebhookController extends Controller
{
    public function __construct(
        protected MetaWhatsAppService $metaService
    ) {}

    /**
     * Handle incoming webhook for new orders from WooCommerce or Shopify.
     *
     * URL: POST /api/webhooks/store/{token}
     */
    public function handle(Request $request, string $token): JsonResponse
    {
        Log::info("StoreWebhook: Received callback for token [{$token}]", [
            'headers' => $request->headers->all(),
            'ip' => $request->ip(),
        ]);

        // 1. Resolve WhatsItem by unique store webhook token
        $whatsItem = WhatsItem::where('store_webhook_token', $token)->first();

        if (! $whatsItem) {
            Log::warning("StoreWebhook: Invalid token provided: {$token}");

            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook token or store not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        // 2. Check if order confirmations are enabled for this WhatsApp number
        if (! $whatsItem->order_confirmation_enabled) {
            Log::info("StoreWebhook: Order confirmation is disabled for WhatsItem #{$whatsItem->id}");

            return response()->json([
                'status' => 'ignored',
                'message' => 'Order confirmation feature is disabled for this WhatsApp number.',
            ], Response::HTTP_OK);
        }

        // 3. Check if account has active subscription with remaining messages
        if (! $whatsItem->hasActiveSubscription()) {
            Log::warning("StoreWebhook: WhatsItem #{$whatsItem->id} does not have an active subscription or messages are depleted.");

            return response()->json([
                'status' => 'limit_exceeded',
                'message' => 'No active subscription or message quota depleted.',
            ], Response::HTTP_OK);
        }

        // 4. Detect platform (WooCommerce vs Shopify)
        $platform = $this->detectPlatform($request, $whatsItem);

        // 5. Extract order details based on platform
        $orderData = match ($platform) {
            'shopify' => $this->parseShopifyPayload($request),
            default => $this->parseWooCommercePayload($request),
        };

        if (empty($orderData['external_order_id'])) {
            Log::warning('StoreWebhook: Missing order ID in payload', ['payload' => $request->all()]);

            return response()->json([
                'status' => false,
                'message' => 'Could not determine external order ID from payload.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($orderData['customer_phone'])) {
            Log::warning("StoreWebhook: Order #{$orderData['external_order_id']} does not contain customer phone number.");

            return response()->json([
                'status' => 'no_phone',
                'message' => 'Order does not have a customer phone number.',
            ], Response::HTTP_OK);
        }

        // 6. Ensure template is registered on Meta WABA
        $templateResult = $this->metaService->ensureOrderConfirmationTemplate($whatsItem);

        // 7. Store order (idempotent: prevent duplicate sending if webhook retries)
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

        // 8. Send WhatsApp template if not already sent
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
     * Detect whether the incoming webhook is from WooCommerce or Shopify.
     */
    protected function detectPlatform(Request $request, WhatsItem $whatsItem): string
    {
        if ($request->hasHeader('X-Shopify-Topic') || $request->hasHeader('X-Shopify-Hmac-Sha256') || $request->hasHeader('X-Shopify-Shop-Domain')) {
            return 'shopify';
        }

        if ($request->hasHeader('X-WC-Webhook-Topic') || $request->hasHeader('X-WC-Webhook-Source')) {
            return 'woocommerce';
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
     * Extract order details from Shopify webhook payload.
     *
     * @return array{external_order_id: ?string, order_number: ?string, customer_name: ?string, customer_phone: ?string, total_price: float, currency: string}
     */
    protected function parseShopifyPayload(Request $request): array
    {
        $id = $request->input('id');
        $orderNumber = $request->input('order_number') ?? $request->input('name') ?? $id;

        $firstName = (string) ($request->input('customer.first_name') ?? $request->input('shipping_address.first_name') ?? '');
        $lastName = (string) ($request->input('customer.last_name') ?? $request->input('shipping_address.last_name') ?? '');
        $customerName = trim("{$firstName} {$lastName}");

        $phone = $request->input('customer.phone')
            ?? $request->input('shipping_address.phone')
            ?? $request->input('billing_address.phone')
            ?? $request->input('phone')
            ?? '';

        $total = (float) ($request->input('current_total_price') ?? $request->input('total_price') ?? 0);
        $currency = (string) ($request->input('currency') ?? 'EGP');

        return [
            'external_order_id' => $id ? (string) $id : null,
            'order_number' => $orderNumber ? (string) $orderNumber : null,
            'customer_name' => $customerName ?: null,
            'customer_phone' => (string) $phone,
            'total_price' => $total,
            'currency' => $currency,
        ];
    }
}
