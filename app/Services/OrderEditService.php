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

            $unitPrice = 0.0;
            if (isset($item['price']) && (float) $item['price'] > 0) {
                $unitPrice = (float) $item['price'];
            } elseif (! empty($item['subtotal']) && ! empty($item['quantity'])) {
                $unitPrice = (float) $item['subtotal'] / (float) $item['quantity'];
            } elseif (! empty($item['total']) && ! empty($item['quantity'])) {
                $unitPrice = (float) $item['total'] / (float) $item['quantity'];
            }

            return [
                'name' => $item['name'] ?? 'منتج',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => $unitPrice ?: ($item['price'] ?? $item['total'] ?? 0),
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

        return $header.$body.$footer;
    }

    /**
     * Use ChatGPT (OpenAI) to parse the customer's edit request or confirmation intent into structured instructions.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $conversationHistory
     * @return array{intent?: string, edits?: array, summary_ar?: string, error?: string}|null
     */
    public function parseEditRequestWithAi(array $items, string $customerMessage, array $conversationHistory = []): ?array
    {
        $itemsContext = $this->buildItemsContextForAi($items);
        $historyText = ! empty($conversationHistory)
            ? implode("\n", $conversationHistory)
            : 'لا يوجد سجل سابق';

        $systemPrompt = <<<'PROMPT'
أنت مساعد ذكي لإدارة وتأكيد وتعديل طلبات المتاجر الإلكترونية عبر واتساب.
أمامك أصناف الطلب الحالي، وسجل المحادثة الأخيرة، والرسالة الحالية من العميل.

مهمتك تحديد نية العميل بدقة من بين 4 حالات حصراً:

1. "confirm": إذا كان فحوى رسالة العميل هو تأكيد أو اعتماد الطلب النهائي أو الموافقة عليه (مثل: "تأكيد", "تاكيد", "تمام", "أكد الطلب", "كده حلو", "اعتمد", "كده تمام التمام", "تمام ابعتهم", "خلاص تمام", "أيوة كده تمام", "اعتمد يا باشا", إلخ).
   الصيغة المطلوبة:
   {
     "intent": "confirm",
     "summary_ar": "تم تأكيد طلبك بنجاح"
   }

2. "edit": إذا كان العميل يطلب أي تعديل على الطلب (تعديل كمية، استبدال منتج بمنتج آخر، حذف صنف، إضافة صنف، تعديل مواصفات).
   أنواع التعديلات المدعومة داخل "edits":
   - "update_quantity": تغيير كمية صنف موجود (يتطلب item_index و new_quantity > 0)
   - "replace_item": استبدال صنف موجود بصنف مختلف (مثل: "بدل الصنف 1 هات فستان قطعتين" أو "غير منتج كذا لـ كذا")، يتطلب item_index و product_name و quantity.
   - "remove_item": حذف صنف موجود (يتطلب item_index)
   - "add_item": إضافة صنف جديد (يتطلب product_name و quantity)
   - "update_attributes": تعديل خصائص فرعية لصنف (مثل المقاس أو اللون)
   الصيغة المطلوبة:
   {
     "intent": "edit",
     "edits": [
       {"action": "update_quantity", "item_index": 1, "new_quantity": 2}
     ],
     "summary_ar": "ملخص التعديلات بالعربية بأسلوب مختصر وودي"
   }

3. "cancel": إذا كان العميل يريد إلغاء التعديل أو التراجع (مثل: "إلغاء التعديل", "تراجع", "رجوع", "مش عاوز اعدل").
   الصيغة المطلوبة:
   {
     "intent": "cancel",
     "summary_ar": "تم إلغاء التعديل"
   }

4. "out_of_context": إذا خرج العميل تماماً عن موضوع الطلب وسأل عن شيء عام غير متعلق بتعديل أو تأكيد هذا الطلب (مثل: السؤال عن مواعيد العمل، فروع المحل، الاستفسار عن كورس، شكوى غير مرتبطة، إلخ).
   الصيغة المطلوبة:
   {
     "intent": "out_of_context"
   }

القواعد الصارمة:
1. أرجع JSON فقط بدون أي نص إضافي أو markdown أو شرح.
2. اقرأ سياق المحادثة بدقة: إذا كان البوت قد طلب من العميل كتابة "تأكيد" والعميل رد بـ "تمام" أو "أكد" أو "كده تمام" أو أي كلمة تعبر عن الرضا والموافقة، فهذه نية "confirm".
3. item_index يبدأ من 1 حسب الترقيم في قائمة أصناف الطلب المرسلة.
4. عند استبدال منتج بمنتج آخر (مثل: "بدل منتج جديد فستان 2 قطعة"): استخدم دائماً "replace_item" مع اسم المنتج الجديد البديل وكميته.
5. ممنوع نهائياً استخدام "update_attributes" لتغيير اسم الصنف أو استبداله أو تغيير كميته!
PROMPT;

        $userMessage = "أصناف الطلب الحالي:\n{$itemsContext}\n\nسجل المحادثة الأخيرة:\n{$historyText}\n\nرسالة العميل الحالية: \"{$customerMessage}\"";

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

            $intent = $parsed['intent'] ?? (! empty($parsed['edits']) ? 'edit' : 'unknown');
            $parsed['intent'] = $intent;

            if ($intent === 'edit' || ! empty($parsed['edits'])) {
                $validEdits = $this->validateEditInstructions($parsed['edits'] ?? [], $items);
                $parsed['edits'] = $validEdits;
                if (! empty($validEdits)) {
                    $parsed['intent'] = 'edit';
                }
            }

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

            if ($action === 'replace_item') {
                if (! empty($edit['product_name'])) {
                    $validated[] = [
                        'action' => 'replace_item',
                        'item_index' => $index,
                        'product_name' => (string) $edit['product_name'],
                        'quantity' => (int) ($edit['quantity'] ?? $edit['new_quantity'] ?? 1),
                    ];
                }

                continue;
            }

            if ($action === 'update_quantity' && ($edit['new_quantity'] ?? 0) > 0) {
                $validated[] = $edit;
            } elseif ($action === 'remove_item') {
                $validated[] = $edit;
            } elseif ($action === 'update_attributes' && ! empty($edit['attributes'])) {
                // Defensive normalization: If AI put replacement or quantity inside attributes
                $attrs = $edit['attributes'];
                $productName = $attrs['النوع'] ?? $attrs['المنتج'] ?? $attrs['اسم المنتج'] ?? null;
                $quantity = $attrs['الكمية'] ?? $attrs['quantity'] ?? $attrs['العدد'] ?? null;

                if (! empty($productName)) {
                    $validated[] = [
                        'action' => 'replace_item',
                        'item_index' => $index,
                        'product_name' => (string) $productName,
                        'quantity' => (int) ($quantity ?: ($edit['new_quantity'] ?? 1)),
                    ];

                    continue;
                }

                if (! empty($quantity) && count($attrs) === 1) {
                    $validated[] = [
                        'action' => 'update_quantity',
                        'item_index' => $index,
                        'new_quantity' => (int) $quantity,
                    ];

                    continue;
                }

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
                    $newQty = (int) $edit['new_quantity'];

                    // Determine unit price
                    $unitPrice = 0.0;
                    if (! empty($items[$idx]['price']) && (float) $items[$idx]['price'] > 0) {
                        $unitPrice = (float) $items[$idx]['price'];
                    }

                    // Fallback to raw_payload if snapshot had missing or zero unit price
                    if ($unitPrice <= 0 && ! empty($order->raw_payload['line_items'])) {
                        foreach ($order->raw_payload['line_items'] as $rawItem) {
                            if (($rawItem['id'] ?? null) == $items[$idx]['platform_item_id']) {
                                $unitPrice = (float) ($rawItem['price'] ?? 0);
                                if ($unitPrice <= 0 && ! empty($rawItem['quantity']) && (int) $rawItem['quantity'] > 0) {
                                    $unitPrice = (float) ($rawItem['total'] ?? $rawItem['subtotal'] ?? 0) / (int) $rawItem['quantity'];
                                }
                                break;
                            }
                        }
                    }

                    $update = [
                        'id' => $items[$idx]['platform_item_id'],
                        'quantity' => $newQty,
                    ];

                    if ($unitPrice > 0) {
                        $subtotalStr = number_format($unitPrice * $newQty, 2, '.', '');
                        $update['subtotal'] = $subtotalStr;
                        $update['total'] = $subtotalStr;
                    }

                    $lineItemUpdates[] = $update;
                }
            } elseif ($action === 'replace_item') {
                $idx = ($edit['item_index'] ?? 0) - 1;
                if (isset($items[$idx]) && ! empty($items[$idx]['platform_item_id'])) {
                    // 1. Remove old item
                    $lineItemUpdates[] = [
                        'id' => $items[$idx]['platform_item_id'],
                        'quantity' => 0,
                    ];

                    // 2. Add replacement product
                    $productName = $edit['product_name'] ?? $edit['new_product_name'] ?? '';
                    $newQty = (int) ($edit['quantity'] ?? $edit['new_quantity'] ?? 1);

                    $productId = $this->searchWooCommerceProduct(
                        $baseUrl,
                        $consumerKey,
                        $consumerSecret,
                        $productName
                    );

                    if ($productId) {
                        $lineItemUpdates[] = [
                            'product_id' => $productId,
                            'quantity' => max(1, $newQty),
                        ];
                    } else {
                        Log::warning("OrderEditService: Replacement product '{$productName}' not found in WooCommerce");
                    }
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

        // Check if order payload has a direct self link (e.g. index.php?rest_route=/wc/v3/orders/23)
        $selfLink = data_get($order->raw_payload, '_links.self.0.href');
        $endpoint = ! empty($selfLink)
            ? (string) $selfLink
            : "{$baseUrl}/wp-json/wc/v3/orders/{$order->external_order_id}";

        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $finalUrl = "{$endpoint}{$separator}consumer_key={$consumerKey}&consumer_secret={$consumerSecret}";

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(20)
                ->put($finalUrl, [
                    'line_items' => $lineItemUpdates,
                ]);

            // Fallback: If pretty URL returned 404, try with index.php?rest_route=
            if ($response->status() === 404 && ! str_contains($endpoint, 'rest_route')) {
                $fallbackUrl = "{$baseUrl}/index.php?rest_route=/wc/v3/orders/{$order->external_order_id}&consumer_key={$consumerKey}&consumer_secret={$consumerSecret}";
                $fallbackResponse = Http::withBasicAuth($consumerKey, $consumerSecret)
                    ->timeout(20)
                    ->put($fallbackUrl, [
                        'line_items' => $lineItemUpdates,
                    ]);

                if ($fallbackResponse->successful()) {
                    $response = $fallbackResponse;
                }
            }

            // If WooCommerce returns invalid item ID error, refresh order and retry with fresh item IDs
            if ($response->status() === 400 && str_contains($response->body(), 'woocommerce_rest_invalid_item_id')) {
                Log::warning("OrderEditService: Invalid item ID detected for order #{$order->external_order_id}. Refreshing order and retrying...");
                $freshOrder = $this->refreshWooCommerceOrder($order);
                if (! empty($freshOrder['line_items'])) {
                    $remappedUpdates = $this->remapWooCommerceItemUpdates($lineItemUpdates, $items, $freshOrder['line_items']);
                    if (! empty($remappedUpdates)) {
                        $retryResponse = Http::withBasicAuth($consumerKey, $consumerSecret)
                            ->timeout(20)
                            ->put($finalUrl, ['line_items' => $remappedUpdates]);

                        if ($retryResponse->status() === 404 && ! str_contains($endpoint, 'rest_route')) {
                            $retryFallbackUrl = "{$baseUrl}/index.php?rest_route=/wc/v3/orders/{$order->external_order_id}&consumer_key={$consumerKey}&consumer_secret={$consumerSecret}";
                            $retryResponse = Http::withBasicAuth($consumerKey, $consumerSecret)
                                ->timeout(20)
                                ->put($retryFallbackUrl, ['line_items' => $remappedUpdates]);
                        }

                        if ($retryResponse->successful()) {
                            $response = $retryResponse;
                        }
                    }
                }
            }

            if ($response->successful()) {
                $updatedOrder = $response->json();
                $newTotal = $updatedOrder['total'] ?? $order->total_price;

                Log::info("OrderEditService: WooCommerce order #{$order->external_order_id} edited successfully");

                return [
                    'success' => true,
                    'message' => 'Order updated successfully on WooCommerce.',
                    'updated_items' => $updatedOrder['line_items'] ?? [],
                    'new_total' => $newTotal,
                    'raw_order' => $updatedOrder,
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
            $params = [
                'consumer_key' => $key,
                'consumer_secret' => $secret,
                'search' => $name,
                'per_page' => 1,
                'status' => 'publish',
            ];

            $response = Http::withBasicAuth($key, $secret)
                ->timeout(10)
                ->get("{$baseUrl}/wp-json/wc/v3/products", $params);

            if ($response->status() === 404 || ! $response->successful()) {
                $params['rest_route'] = '/wc/v3/products';
                $response = Http::withBasicAuth($key, $secret)
                    ->timeout(10)
                    ->get("{$baseUrl}/index.php", $params);
            }

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
     * Remap outdated line item IDs to fresh live line item IDs from WooCommerce.
     *
     * @param  array<int, array<string, mixed>>  $updates
     * @param  array<int, array<string, mixed>>  $oldItems
     * @param  array<int, array<string, mixed>>  $freshLineItems
     * @return array<int, array<string, mixed>>
     */
    protected function remapWooCommerceItemUpdates(array $updates, array $oldItems, array $freshLineItems): array
    {
        $remapped = [];
        foreach ($updates as $update) {
            if (empty($update['id'])) {
                $remapped[] = $update;

                continue;
            }

            $oldId = $update['id'];
            $matchedFreshId = null;

            $targetOld = null;
            foreach ($oldItems as $oldItem) {
                if (($oldItem['platform_item_id'] ?? null) == $oldId) {
                    $targetOld = $oldItem;
                    break;
                }
            }

            // Match by product_id
            if ($targetOld && ! empty($targetOld['product_id'])) {
                foreach ($freshLineItems as $fItem) {
                    if (($fItem['product_id'] ?? null) == $targetOld['product_id']) {
                        $matchedFreshId = $fItem['id'];
                        break;
                    }
                }
            }

            // Fallback match by index
            if (! $matchedFreshId && $targetOld && isset($freshLineItems[($targetOld['index'] ?? 1) - 1])) {
                $matchedFreshId = $freshLineItems[($targetOld['index'] ?? 1) - 1]['id'];
            }

            // Fallback: If only 1 line item exists in the fresh order, map to it
            if (! $matchedFreshId && count($freshLineItems) === 1) {
                $matchedFreshId = $freshLineItems[0]['id'];
            }

            if ($matchedFreshId) {
                $update['id'] = $matchedFreshId;
                $remapped[] = $update;
            }
        }

        return $remapped;
    }

    /**
     * Fetch fresh live order data from the store platform (WooCommerce / Shopify) and update local order.
     *
     * @return array<string, mixed>|null
     */
    public function refreshOrderFromPlatform(EcommerceOrder $order): ?array
    {
        $platform = strtolower((string) ($order->platform ?: 'woocommerce'));

        if ($platform === 'shopify') {
            return $this->refreshShopifyOrder($order);
        }

        return $this->refreshWooCommerceOrder($order);
    }

    /**
     * Refresh WooCommerce order details from store REST API.
     *
     * @return array<string, mixed>|null
     */
    public function refreshWooCommerceOrder(EcommerceOrder $order): ?array
    {
        $whatsItem = $order->whatsItem;
        if (! $whatsItem) {
            return null;
        }

        $baseUrl = rtrim((string) $whatsItem->website_url, '/');
        if (empty($baseUrl)) {
            return null;
        }

        if (! str_starts_with($baseUrl, 'http://') && ! str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://'.$baseUrl;
        }

        $consumerKey = (string) $whatsItem->user_name;
        $consumerSecret = (string) $whatsItem->password;

        if (empty($consumerKey) || empty($consumerSecret)) {
            return null;
        }

        $selfLink = data_get($order->raw_payload, '_links.self.0.href');
        $endpoint = ! empty($selfLink)
            ? (string) $selfLink
            : "{$baseUrl}/wp-json/wc/v3/orders/{$order->external_order_id}";

        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $finalUrl = "{$endpoint}{$separator}consumer_key={$consumerKey}&consumer_secret={$consumerSecret}";

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->get($finalUrl);

            if ($response->status() === 404 && ! str_contains($endpoint, 'rest_route')) {
                $fallbackUrl = "{$baseUrl}/index.php?rest_route=/wc/v3/orders/{$order->external_order_id}&consumer_key={$consumerKey}&consumer_secret={$consumerSecret}";
                $fallbackResponse = Http::withBasicAuth($consumerKey, $consumerSecret)
                    ->timeout(15)
                    ->get($fallbackUrl);

                if ($fallbackResponse->successful()) {
                    $response = $fallbackResponse;
                }
            }

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data) && ! empty($data['id'])) {
                    $order->update([
                        'raw_payload' => $data,
                        'total_price' => $data['total'] ?? $order->total_price,
                    ]);

                    return $data;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("OrderEditService: Could not refresh WooCommerce order #{$order->external_order_id}: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Refresh Shopify order details from Admin API.
     *
     * @return array<string, mixed>|null
     */
    public function refreshShopifyOrder(EcommerceOrder $order): ?array
    {
        $whatsItem = $order->whatsItem;
        if (! $whatsItem) {
            return null;
        }

        $domain = rtrim((string) $whatsItem->website_url, '/');
        $accessToken = (string) $whatsItem->custom_api_key;

        if (empty($domain) || empty($accessToken)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])->timeout(15)->get("https://{$domain}/admin/api/2024-01/orders/{$order->external_order_id}.json");

            if ($response->successful()) {
                $data = $response->json('order');
                if (is_array($data)) {
                    $order->update([
                        'raw_payload' => $data,
                        'total_price' => $data['total_price'] ?? $order->total_price,
                    ]);

                    return $data;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("OrderEditService: Could not refresh Shopify order #{$order->external_order_id}: {$e->getMessage()}");
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
                    'raw_order' => $data,
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
     * Check if a text message clearly conveys customer confirmation intent.
     */
    public function isConfirmationIntent(string $text): bool
    {
        $trimmed = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $text));
        $confirmExact = [
            'تأكيد', 'تاكيد', 'أكد', 'اكد', 'اعتمد', 'تمام', 'موافق', 'كده تمام', 'تمام كدة',
            'تمام كده', 'كده مظبوط', 'كده تمام يا غالي', 'اعتمد الطلب', 'أكد الطلب', 'اكد الطلب',
            'تمام اعتمد', 'تمام توكل على الله', 'خلاص تمام', 'خلاص اكد', 'أيوة تمام', 'ايوه تمام',
            'ايوه', 'أيوة', 'تمام جدا', 'تسلم تمام', 'كده حلو', 'اعتمد يا باشا', 'تمام كده تسلم',
            'تمام شكرا', 'شكرا خلاص', 'نعم', 'اكيد', 'أكيد', 'اوك', 'اوكي', 'ok',
        ];

        return in_array($trimmed, $confirmExact, true);
    }

    /**
     * Build an updated order summary message to send back to the customer after each edit.
     * Shows current order items and invites further edits or confirmation.
     */
    public function buildUpdatedOrderSummary(EcommerceOrder $order, array $editResult, string $aiSummary = ''): string
    {
        $orderNumber = $order->order_number ?: $order->external_order_id;
        $currency = $order->currency ?: 'EGP';
        $items = $order->edit_items_snapshot ?? $this->extractLineItems($order);

        $message = "✅ *تم تعديل طلبك رقم #{$orderNumber} بنجاح!*\n\n";

        if (! empty($aiSummary)) {
            $message .= "📝 *التعديلات:*\n{$aiSummary}\n\n";
        }

        $message .= "📋 *تفاصيل الطلب بعد التعديل:*\n";
        $message .= "━━━━━━━━━━━━━━━━\n";

        $lines = [];
        foreach ($items as $item) {
            $line = "*{$item['index']}.* {$item['name']}";
            if (! empty($item['variant'])) {
                $line .= " ({$item['variant']})";
            }
            $line .= "\n   📦 الكمية: {$item['quantity']} | 💰 السعر: {$item['price']} {$currency}";
            if (! empty($item['attributes'])) {
                foreach ($item['attributes'] as $key => $value) {
                    $line .= "\n   🏷️ {$key}: {$value}";
                }
            }
            $lines[] = $line;
        }

        $message .= implode("\n\n", $lines);
        $total = $editResult['new_total'] ?? $order->total_price ?: '0';
        $message .= "\n━━━━━━━━━━━━━━━━\n";
        $message .= "💵 *الإجمالي الجديد: {$total} {$currency}*\n\n";
        $message .= "✏️ *اكتب تعديل إضافي لو حابب، أو اكتب 'تأكيد' لتأكيد الطلب نهائياً.*";

        return $message;
    }

    /**
     * Build a final confirmation message once the customer confirms their edited order.
     */
    public function buildFinalConfirmationMessage(EcommerceOrder $order): string
    {
        $orderNumber = $order->order_number ?: $order->external_order_id;
        $currency = $order->currency ?: 'EGP';
        $items = $order->edit_items_snapshot ?? $this->extractLineItems($order);

        $message = "✅ *تم تأكيد طلبك رقم #{$orderNumber} بنجاح!*\n";
        $message .= "━━━━━━━━━━━━━━━━\n";

        $lines = [];
        foreach ($items as $item) {
            $line = "*{$item['index']}.* {$item['name']}";
            if (! empty($item['variant'])) {
                $line .= " ({$item['variant']})";
            }
            $line .= "\n   📦 الكمية: {$item['quantity']} | 💰 السعر: {$item['price']} {$currency}";
            if (! empty($item['attributes'])) {
                foreach ($item['attributes'] as $key => $value) {
                    $line .= "\n   🏷️ {$key}: {$value}";
                }
            }
            $lines[] = $line;
        }

        $message .= implode("\n\n", $lines);
        $total = $order->total_price ?: '0';
        $message .= "\n━━━━━━━━━━━━━━━━\n";
        $message .= "💵 *الإجمالي النهائي: {$total} {$currency}*\n\n";
        $message .= "🚀 جارٍ تجهيز طلبك وتوصيله إليك في أقرب وقت.\nشكراً لاختيارك لنا! 🛍️";

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

        $message .= "\nيرجى المحاولة مرة أخرى أو التواصل مع خدمة العملاء للمساعدة.";

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
            ."• _بدل الصنف 1 بـ فستان قطعتين_\n"
            ."• _شيل الصنف 1_\n"
            ."• _ضيف 2 كذا_\n\n"
            .'أو اكتب *تأكيد* لتأكيد الطلب الحالي، أو *إلغاء التعديل* للرجوع.';
    }
}
