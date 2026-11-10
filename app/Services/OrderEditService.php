<?php

namespace App\Services;

use App\Models\EcommerceOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class OrderEditService
{
    /**
     * Extract line items from the order's raw_payload based on platform.
     *
     * @return array<int, array{index: int, name: string, quantity: int, price: float|string, variant: ?string, attributes: array, platform_item_id: string|int|null, product_id: string|int|null, variant_id: string|int|null, sku: ?string}>
     */
    public function extractLineItems(EcommerceOrder $order): array
    {
        $payload = $order->raw_payload ?? [];
        $platform = strtolower((string) ($order->platform ?: 'woocommerce'));

        $rawItems = match ($platform) {
            'shopify' => $this->extractShopifyItems($payload),
            'custom' => $this->extractCustomItems($payload),
            default => $this->extractWooCommerceItems($payload),
        };

        // Reindex from 1
        return array_values(array_map(function (array $item, int $idx) {
            $item['index'] = $idx + 1;

            return $item;
        }, $rawItems, array_keys($rawItems)));
    }

    /**
     * Extract line items from WooCommerce payload.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractWooCommerceItems(array $payload): array
    {
        $lineItems = $payload['line_items'] ?? [];

        return array_map(function (array $item) {
            $attributes = [];
            foreach ($item['meta_data'] ?? [] as $meta) {
                if (! empty($meta['key']) && ! str_starts_with($meta['key'], '_')) {
                    $attributes[$meta['key']] = $meta['value'] ?? '';
                }
            }

            return [
                'name' => $item['name'] ?? 'منتج',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => $item['price'] ?? $item['total'] ?? 0,
                'variant' => ! empty($item['variation_id']) ? ('Variation #'.$item['variation_id']) : null,
                'attributes' => $attributes,
                'platform_item_id' => $item['id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'variant_id' => $item['variation_id'] ?? null,
                'sku' => $item['sku'] ?? null,
            ];
        }, $lineItems);
    }

    /**
     * Extract line items from Shopify payload.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractShopifyItems(array $payload): array
    {
        $lineItems = $payload['line_items'] ?? [];

        return array_map(function (array $item) {
            $attributes = [];
            foreach ($item['properties'] ?? [] as $prop) {
                if (! empty($prop['name'])) {
                    $attributes[$prop['name']] = $prop['value'] ?? '';
                }
            }

            return [
                'name' => $item['name'] ?? $item['title'] ?? 'منتج',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => $item['price'] ?? 0,
                'variant' => $item['variant_title'] ?? null,
                'attributes' => $attributes,
                'platform_item_id' => $item['id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'variant_id' => $item['variant_id'] ?? null,
                'sku' => $item['sku'] ?? null,
            ];
        }, $lineItems);
    }

    /**
     * Extract line items from Custom platform payload.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractCustomItems(array $payload): array
    {
        $lineItems = $payload['line_items']
            ?? $payload['items']
            ?? $payload['products']
            ?? $payload['order_items']
            ?? [];

        return array_map(function (array $item) {
            $attributes = [];
            foreach ($item['attributes'] ?? $item['meta_data'] ?? $item['options'] ?? [] as $key => $value) {
                if (is_array($value) && isset($value['key'])) {
                    $attributes[$value['key']] = $value['value'] ?? '';
                } elseif (is_string($key)) {
                    $attributes[$key] = is_string($value) ? $value : json_encode($value);
                }
            }

            return [
                'name' => $item['name'] ?? $item['title'] ?? $item['product_name'] ?? 'منتج',
                'quantity' => (int) ($item['quantity'] ?? $item['qty'] ?? 1),
                'price' => $item['price'] ?? $item['unit_price'] ?? $item['total'] ?? 0,
                'variant' => $item['variant'] ?? $item['variant_title'] ?? null,
                'attributes' => $attributes,
                'platform_item_id' => $item['id'] ?? $item['item_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'variant_id' => $item['variant_id'] ?? $item['variation_id'] ?? null,
                'sku' => $item['sku'] ?? null,
            ];
        }, $lineItems);
    }

    /**
     * Format extracted line items as a numbered list message for WhatsApp.
     */
    public function formatItemsListMessage(array $items, EcommerceOrder $order): string
    {
        $currency = $order->currency ?: 'EGP';
        $orderNumber = $order->order_number ?: $order->external_order_id;

        $header = "📋 *طلبك رقم #{$orderNumber}*\n";
        $header .= "━━━━━━━━━━━━━━━━\n";

        $lines = [];
        foreach ($items as $item) {
            $line = "*{$item['index']}.* {$item['name']}";

            if (! empty($item['variant'])) {
                $line .= " ({$item['variant']})";
            }

            $line .= "\n   📦 الكمية: {$item['quantity']}";
            $line .= " | 💰 السعر: {$item['price']} {$currency}";

            if (! empty($item['attributes'])) {
                foreach ($item['attributes'] as $key => $value) {
                    $line .= "\n   🏷️ {$key}: {$value}";
                }
            }

            $lines[] = $line;
        }

        $body = implode("\n\n", $lines);
        $total = $order->total_price ?: '0';

        $footer = "\n━━━━━━━━━━━━━━━━\n";
        $footer .= "💵 *الإجمالي: {$total} {$currency}*\n\n";
        $footer .= "✏️ *اكتب التعديل المطلوب*\n";
        $footer .= '_مثال: غير كمية الصنف 2 لـ 3، أو شيل الصنف 1، أو ضيف 2 بيتزا مارجريتا_';

        return $header.$body.$footer;
    }

    /**
     * Use ChatGPT (OpenAI) to parse the customer's edit request into structured instructions.
     *
     * @return array{edits: array, summary_ar: string, error?: string}|null
     */
    public function parseEditRequestWithAi(array $items, string $customerMessage): ?array
    {
        $itemsContext = $this->buildItemsContextForAi($items);

        $systemPrompt = <<<'PROMPT'
أنت مساعد ذكي متخصص في تعديل طلبات المتاجر الإلكترونية.
سيتم إرسال قائمة بأصناف الطلب الحالي ورسالة العميل.
مطلوب منك تحليل رسالة العميل وإرجاع JSON يحتوي على التعديلات المطلوبة.

القواعد الصارمة:
1. أرجع JSON فقط بدون أي نص إضافي أو markdown أو شرح.
2. أنواع التعديلات المدعومة:
   - "update_quantity": تغيير كمية صنف موجود (يجب أن يكون item_index صحيح وnew_quantity > 0)
   - "remove_item": حذف صنف موجود (يجب أن يكون item_index صحيح)
   - "update_attributes": تعديل خصائص صنف (مقاس، لون، حجم، إلخ)
   - "add_item": إضافة صنف جديد (يجب تحديد product_name والquantity)
3. item_index يبدأ من 1 حسب الترقيم في القائمة المرسلة.
4. تأكد أن item_index يشير لصنف موجود فعلاً في القائمة.
5. إذا طلب العميل تعديل صنف غير موجود أو رقم غير صحيح، أرجع error.
6. إذا كانت الرسالة غير واضحة أو لا تتعلق بتعديل الطلب، أرجع edits فارغ مع error.
7. لا تخترع أي أصناف أو أسعار. اعمل فقط بناءً على ما هو مذكور في القائمة ورسالة العميل.

الصيغة المطلوبة:
{
  "edits": [
    {"action": "update_quantity", "item_index": 2, "new_quantity": 3},
    {"action": "remove_item", "item_index": 4},
    {"action": "update_attributes", "item_index": 1, "attributes": {"المقاس": "كبير"}},
    {"action": "add_item", "product_name": "اسم المنتج", "quantity": 1}
  ],
  "summary_ar": "ملخص التعديلات بالعربية بأسلوب مختصر وودي"
}
PROMPT;

        $userMessage = "الطلب الحالي:\n{$itemsContext}\n\nرسالة العميل: \"{$customerMessage}\"";

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $response = OpenAI::chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = trim($response->choices[0]->message->content ?? '');

            Log::info('OrderEditService: AI raw response', ['content' => $content]);

            $parsed = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($parsed)) {
                Log::warning('OrderEditService: AI returned invalid JSON', ['content' => $content]);

                return null;
            }

            // Validate edit instructions
            $validEdits = $this->validateEditInstructions($parsed['edits'] ?? [], $items);
            $parsed['edits'] = $validEdits;

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('OrderEditService: AI parsing failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Validate that edit instructions reference valid items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function validateEditInstructions(array $edits, array $items): array
    {
        $maxIndex = count($items);
        $validated = [];

        foreach ($edits as $edit) {
            $action = $edit['action'] ?? '';

            if ($action === 'add_item') {
                if (! empty($edit['product_name']) && ($edit['quantity'] ?? 0) > 0) {
                    $validated[] = $edit;
                }

                continue;
            }

            $index = $edit['item_index'] ?? 0;
            if ($index < 1 || $index > $maxIndex) {
                Log::warning("OrderEditService: Invalid item_index {$index}, max is {$maxIndex}");

                continue;
            }

            if ($action === 'update_quantity' && ($edit['new_quantity'] ?? 0) > 0) {
                $validated[] = $edit;
            } elseif ($action === 'remove_item') {
                $validated[] = $edit;
            } elseif ($action === 'update_attributes' && ! empty($edit['attributes'])) {
                $validated[] = $edit;
            }
        }

        return $validated;
    }

    /**
     * Build a textual representation of items for the AI context.
     */
    protected function buildItemsContextForAi(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $line = "{$item['index']}. {$item['name']}";
            if (! empty($item['variant'])) {
                $line .= " ({$item['variant']})";
            }
            $line .= " - الكمية: {$item['quantity']} - السعر: {$item['price']}";
            if (! empty($item['attributes'])) {
                foreach ($item['attributes'] as $key => $value) {
                    $line .= " - {$key}: {$value}";
                }
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Apply edit instructions to the order on the external platform.
     *
     * @param  array{edits: array, summary_ar: string}  $editInstructions
     * @return array{success: bool, message: string, updated_items?: array, new_total?: float|string}
     */
    public function applyEdits(EcommerceOrder $order, array $editInstructions): array
    {
        $platform = strtolower((string) ($order->platform ?: 'woocommerce'));

        return match ($platform) {
            'shopify' => $this->editShopifyOrder($order, $editInstructions),
            'custom' => $this->editCustomOrder($order, $editInstructions),
            default => $this->editWooCommerceOrder($order, $editInstructions),
        };
    }

    /**
     * Edit a WooCommerce order via REST API.
     *
     * @return array{success: bool, message: string, updated_items?: array, new_total?: float|string}
     */
    protected function editWooCommerceOrder(EcommerceOrder $order, array $editInstructions): array
    {
        $whatsItem = $order->whatsItem;
        if (! $whatsItem) {
            return ['success' => false, 'message' => 'WhatsApp channel not linked.'];
        }

        $baseUrl = $whatsItem->website_url ?: $whatsItem->api_link;
        if (empty($baseUrl)) {
            return ['success' => false, 'message' => 'WooCommerce store URL is missing.'];
        }

        $baseUrl = rtrim($baseUrl, '/');
        if (! str_starts_with($baseUrl, 'http://') && ! str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://'.$baseUrl;
        }

        $consumerKey = (string) $whatsItem->user_name;
        $consumerSecret = (string) $whatsItem->password;

        if (empty($consumerKey) || empty($consumerSecret)) {
            return ['success' => false, 'message' => 'WooCommerce credentials missing.'];
        }

        $items = $order->edit_items_snapshot ?? $this->extractLineItems($order);
        $lineItemUpdates = [];

        foreach ($editInstructions['edits'] ?? [] as $edit) {
            $action = $edit['action'] ?? '';

            if ($action === 'update_quantity') {
                $idx = ($edit['item_index'] ?? 0) - 1;
                if (isset($items[$idx]) && ! empty($items[$idx]['platform_item_id'])) {
                    $lineItemUpdates[] = [
                        'id' => $items[$idx]['platform_item_id'],
                        'quantity' => (int) $edit['new_quantity'],
                    ];
                }
            } elseif ($action === 'remove_item') {
                $idx = ($edit['item_index'] ?? 0) - 1;
                if (isset($items[$idx]) && ! empty($items[$idx]['platform_item_id'])) {
                    $lineItemUpdates[] = [
                        'id' => $items[$idx]['platform_item_id'],
                        'quantity' => 0,
                    ];
                }
            } elseif ($action === 'update_attributes') {
                $idx = ($edit['item_index'] ?? 0) - 1;
                if (isset($items[$idx]) && ! empty($items[$idx]['platform_item_id'])) {
                    $metaData = [];
                    foreach ($edit['attributes'] ?? [] as $key => $value) {
                        $metaData[] = ['key' => $key, 'value' => $value];
                    }
                    $lineItemUpdates[] = [
                        'id' => $items[$idx]['platform_item_id'],
                        'meta_data' => $metaData,
                    ];
                }
            } elseif ($action === 'add_item') {
                $productId = $this->searchWooCommerceProduct(
                    $baseUrl,
                    $consumerKey,
                    $consumerSecret,
                    $edit['product_name'] ?? ''
                );

                if ($productId) {
                    $lineItemUpdates[] = [
                        'product_id' => $productId,
                        'quantity' => (int) ($edit['quantity'] ?? 1),
                    ];
                }
            }
        }

        if (empty($lineItemUpdates)) {
            return ['success' => false, 'message' => 'لم يتم تحديد أي تعديلات صالحة.'];
        }

        $endpoint = "{$baseUrl}/wp-json/wc/v3/orders/{$order->external_order_id}";

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(20)
                ->put($endpoint, [
                    'line_items' => $lineItemUpdates,
                ]);

            if ($response->successful()) {
                $updatedOrder = $response->json();
                $newTotal = $updatedOrder['total'] ?? $order->total_price;

                Log::info("OrderEditService: WooCommerce order #{$order->external_order_id} edited successfully");

                return [
                    'success' => true,
                    'message' => 'Order updated successfully on WooCommerce.',
                    'updated_items' => $updatedOrder['line_items'] ?? [],
                    'new_total' => $newTotal,
                ];
            }

            Log::error('OrderEditService: WooCommerce edit failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return ['success' => false, 'message' => 'فشل تعديل الطلب على المتجر: '.$response->status()];
        } catch (\Throwable $e) {
            Log::error('OrderEditService: WooCommerce edit exception: '.$e->getMessage());

            return ['success' => false, 'message' => 'خطأ في الاتصال بالمتجر.'];
        }
    }

    /**
     * Search WooCommerce products by name to resolve product_id for add_item.
     */
    protected function searchWooCommerceProduct(string $baseUrl, string $key, string $secret, string $name): ?int
    {
        if (empty($name)) {
            return null;
        }

        try {
            $response = Http::withBasicAuth($key, $secret)
                ->timeout(10)
                ->get("{$baseUrl}/wp-json/wc/v3/products", [
                    'search' => $name,
                    'per_page' => 1,
                    'status' => 'publish',
                ]);

            if ($response->successful()) {
                $products = $response->json();

                return ! empty($products[0]['id']) ? (int) $products[0]['id'] : null;
            }
        } catch (\Throwable $e) {
            Log::warning("OrderEditService: WooCommerce product search failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Edit a Shopify order using GraphQL Admin API staged edit workflow.
     *
     * @return array{success: bool, message: string, updated_items?: array, new_total?: float|string}
     */
    protected function editShopifyOrder(EcommerceOrder $order, array $editInstructions): array
    {
        $whatsItem = $order->whatsItem;
        if (! $whatsItem) {
            return ['success' => false, 'message' => 'WhatsApp channel not linked.'];
        }

        $domain = $whatsItem->website_url;
        if (empty($domain)) {
            return ['success' => false, 'message' => 'Shopify shop domain is missing.'];
        }

        $domain = preg_replace('#^https?://#', '', rtrim($domain, '/'));

        /** @var EcommerceStoreService $storeService */
        $storeService = app(EcommerceStoreService::class);
        $accessToken = $storeService->resolveShopifyAccessToken($whatsItem, $domain);

        if (empty($accessToken)) {
            return ['success' => false, 'message' => 'Shopify access token missing.'];
        }

        $graphqlUrl = "https://{$domain}/admin/api/2024-01/graphql.json";
        $items = $order->edit_items_snapshot ?? $this->extractLineItems($order);

        // Step 1: Begin edit session
        $orderId = $order->external_order_id;
        $orderGid = "gid://shopify/Order/{$orderId}";

        $beginResult = $this->shopifyGraphql($graphqlUrl, $accessToken, '
            mutation orderEditBegin($id: ID!) {
                orderEditBegin(id: $id) {
                    calculatedOrder {
                        id
                        lineItems(first: 50) {
                            edges {
                                node {
                                    id
                                    title
                                    quantity
                                }
                            }
                        }
                    }
                    userErrors { field message }
                }
            }
        ', ['id' => $orderGid]);

        $editData = $beginResult['data']['orderEditBegin'] ?? null;
        if (! empty($editData['userErrors'])) {
            $err = $editData['userErrors'][0]['message'] ?? 'Unknown error';
            Log::error("OrderEditService: Shopify orderEditBegin failed: {$err}");

            return ['success' => false, 'message' => "فشل بدء تعديل الطلب: {$err}"];
        }

        $calculatedOrderId = $editData['calculatedOrder']['id'] ?? null;
        if (! $calculatedOrderId) {
            return ['success' => false, 'message' => 'Could not start Shopify edit session.'];
        }

        // Map calculated line items to original indexes
        $calcLineItems = [];
        foreach ($editData['calculatedOrder']['lineItems']['edges'] ?? [] as $edge) {
            $calcLineItems[] = $edge['node'];
        }

        // Step 2: Apply each edit
        $hasEdits = false;
        foreach ($editInstructions['edits'] ?? [] as $edit) {
            $action = $edit['action'] ?? '';

            if ($action === 'update_quantity' || $action === 'remove_item') {
                $idx = ($edit['item_index'] ?? 0) - 1;
                $newQty = $action === 'remove_item' ? 0 : (int) ($edit['new_quantity'] ?? 1);

                if (isset($calcLineItems[$idx])) {
                    $lineItemGid = $calcLineItems[$idx]['id'];
                    $result = $this->shopifyGraphql($graphqlUrl, $accessToken, '
                        mutation orderEditSetQuantity($id: ID!, $lineItemId: ID!, $quantity: Int!) {
                            orderEditSetQuantity(id: $id, lineItemId: $lineItemId, quantity: $quantity, restock: true) {
                                calculatedOrder { id }
                                userErrors { field message }
                            }
                        }
                    ', [
                        'id' => $calculatedOrderId,
                        'lineItemId' => $lineItemGid,
                        'quantity' => $newQty,
                    ]);

                    if (empty($result['data']['orderEditSetQuantity']['userErrors'])) {
                        $hasEdits = true;
                    } else {
                        Log::warning('OrderEditService: Shopify setQuantity error', [
                            'errors' => $result['data']['orderEditSetQuantity']['userErrors'],
                        ]);
                    }
                }
            } elseif ($action === 'add_item') {
                // Search for the product variant
                $variantGid = $this->searchShopifyProduct(
                    $domain,
                    $accessToken,
                    $edit['product_name'] ?? ''
                );

                if ($variantGid) {
                    $result = $this->shopifyGraphql($graphqlUrl, $accessToken, '
                        mutation orderEditAddVariant($id: ID!, $variantId: ID!, $quantity: Int!) {
                            orderEditAddVariant(id: $id, variantId: $variantId, quantity: $quantity) {
                                calculatedOrder { id }
                                userErrors { field message }
                            }
                        }
                    ', [
                        'id' => $calculatedOrderId,
                        'variantId' => $variantGid,
                        'quantity' => (int) ($edit['quantity'] ?? 1),
                    ]);

                    if (empty($result['data']['orderEditAddVariant']['userErrors'])) {
                        $hasEdits = true;
                    }
                }
            }
        }

        if (! $hasEdits) {
            return ['success' => false, 'message' => 'لم تنجح أي تعديلات على Shopify.'];
        }

        // Step 3: Commit the edit
        $commitResult = $this->shopifyGraphql($graphqlUrl, $accessToken, '
            mutation orderEditCommit($id: ID!) {
                orderEditCommit(id: $id, notifyCustomer: false, staffNote: "تم تعديل الطلب بواسطة العميل عبر واتساب") {
                    order {
                        id
                        name
                        currentTotalPriceSet {
                            shopMoney { amount currencyCode }
                        }
                    }
                    userErrors { field message }
                }
            }
        ', ['id' => $calculatedOrderId]);

        $commitData = $commitResult['data']['orderEditCommit'] ?? null;
        if (! empty($commitData['userErrors'])) {
            $err = $commitData['userErrors'][0]['message'] ?? 'Unknown error';

            return ['success' => false, 'message' => "فشل حفظ التعديلات: {$err}"];
        }

        $newTotal = $commitData['order']['currentTotalPriceSet']['shopMoney']['amount'] ?? $order->total_price;

        Log::info("OrderEditService: Shopify order #{$orderId} edited and committed successfully");

        return [
            'success' => true,
            'message' => 'Order updated successfully on Shopify.',
            'new_total' => $newTotal,
        ];
    }

    /**
     * Execute a GraphQL request against Shopify Admin API.
     *
     * @return array<string, mixed>
     */
    protected function shopifyGraphql(string $url, string $accessToken, string $query, array $variables = []): array
    {
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);

            return $response->json() ?: [];
        } catch (\Throwable $e) {
            Log::error("OrderEditService: Shopify GraphQL exception: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Search Shopify products by title to resolve variant GID for add_item.
     */
    protected function searchShopifyProduct(string $domain, string $accessToken, string $name): ?string
    {
        if (empty($name)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])->timeout(10)->get("https://{$domain}/admin/api/2024-01/products.json", [
                'title' => $name,
                'limit' => 1,
                'fields' => 'id,title,variants',
            ]);

            if ($response->successful()) {
                $products = $response->json('products', []);
                if (! empty($products[0]['variants'][0]['id'])) {
                    return 'gid://shopify/ProductVariant/'.$products[0]['variants'][0]['id'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("OrderEditService: Shopify product search failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Edit a custom platform order by POSTing structured edit data to the configured edit URL.
     *
     * @return array{success: bool, message: string, updated_items?: array, new_total?: float|string}
     */
    protected function editCustomOrder(EcommerceOrder $order, array $editInstructions): array
    {
        $whatsItem = $order->whatsItem;
        if (! $whatsItem) {
            return ['success' => false, 'message' => 'WhatsApp channel not linked.'];
        }

        $editUrl = $whatsItem->api_edit_url;
        if (empty($editUrl)) {
            // If no dedicated edit URL, use confirm URL with edit payload
            $editUrl = $whatsItem->api_confirm_url;
        }

        if (empty($editUrl)) {
            return ['success' => false, 'message' => 'Custom platform edit URL is not configured.'];
        }

        $apiKey = (string) ($whatsItem->custom_api_key ?: ($whatsItem->store_webhook_token ?: ''));
        $externalOrderId = (string) ($order->external_order_id ?: $order->id);

        $payload = [
            'action' => 'edit_order',
            'order_id' => $externalOrderId,
            'edits' => $editInstructions['edits'] ?? [],
            'summary' => $editInstructions['summary_ar'] ?? '',
            'items_snapshot' => $order->edit_items_snapshot ?? [],
        ];

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($editUrl, $payload);

            if ($response->successful()) {
                $data = $response->json() ?: [];
                Log::info("OrderEditService: Custom order #{$externalOrderId} edit sent successfully");

                return [
                    'success' => true,
                    'message' => 'Order edit sent to custom platform.',
                    'updated_items' => $data['items'] ?? $data['line_items'] ?? [],
                    'new_total' => $data['total'] ?? $data['new_total'] ?? $order->total_price,
                ];
            }

            Log::error('OrderEditService: Custom edit failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return ['success' => false, 'message' => 'فشل إرسال التعديل: '.$response->status()];
        } catch (\Throwable $e) {
            Log::error('OrderEditService: Custom edit exception: '.$e->getMessage());

            return ['success' => false, 'message' => 'خطأ في الاتصال بالمنصة.'];
        }
    }

    /**
     * Build an updated order summary message to send back to the customer.
     */
    public function buildUpdatedOrderSummary(EcommerceOrder $order, array $editResult, string $aiSummary = ''): string
    {
        $orderNumber = $order->order_number ?: $order->external_order_id;
        $currency = $order->currency ?: 'EGP';

        $message = "✅ *تم تعديل طلبك رقم #{$orderNumber} بنجاح!*\n\n";

        if (! empty($aiSummary)) {
            $message .= "📝 التعديلات:\n{$aiSummary}\n\n";
        }

        if (isset($editResult['new_total'])) {
            $message .= "💵 *الإجمالي الجديد: {$editResult['new_total']} {$currency}*\n";
        }

        $message .= "\nشكراً لاختيارك لنا! 🛍️";

        return $message;
    }

    /**
     * Build an error message when the edit fails.
     */
    public function buildEditErrorMessage(EcommerceOrder $order, string $reason = ''): string
    {
        $orderNumber = $order->order_number ?: $order->external_order_id;

        $message = "❌ عذراً، لم نتمكن من تعديل طلبك رقم #{$orderNumber}.\n";

        if (! empty($reason)) {
            $message .= "السبب: {$reason}\n";
        }

        $message .= "\nيرجى التواصل مع خدمة العملاء للمساعدة.";

        return $message;
    }

    /**
     * Build a message when AI cannot understand the edit request.
     */
    public function buildClarificationMessage(EcommerceOrder $order): string
    {
        return "🤔 عذراً، لم أفهم التعديل المطلوب.\n\n"
            ."يرجى كتابة التعديل بوضوح مثل:\n"
            ."• _غير كمية الصنف 2 لـ 3_\n"
            ."• _شيل الصنف 1_\n"
            ."• _ضيف 2 بيتزا مارجريتا_\n"
            ."• _غير المقاس بتاع الصنف 3 لـ كبير_\n\n"
            .'أو اكتب *إلغاء التعديل* للرجوع.';
    }
}
