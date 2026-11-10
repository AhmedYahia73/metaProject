<?php

use App\Models\EcommerceOrder;
use App\Services\OrderEditService;

test('OrderEditService extracts WooCommerce line items properly', function () {
    $service = new OrderEditService;

    $order = new EcommerceOrder;
    $order->platform = 'woocommerce';
    $order->raw_payload = [
        'line_items' => [
            [
                'id' => 101,
                'name' => 'بيتزا مارجريتا',
                'product_id' => 50,
                'variation_id' => 0,
                'quantity' => 2,
                'price' => 150.0,
                'meta_data' => [
                    ['id' => 1, 'key' => 'الحجم', 'value' => 'كبير'],
                    ['id' => 2, 'key' => '_hidden_meta', 'value' => 'ignore_me'],
                ],
            ],
            [
                'id' => 102,
                'name' => 'برجر دجاج',
                'product_id' => 51,
                'variation_id' => 52,
                'quantity' => 1,
                'price' => 90.0,
                'meta_data' => [],
            ],
        ],
    ];

    $items = $service->extractLineItems($order);

    expect($items)->toHaveCount(2)
        ->and($items[0]['index'])->toBe(1)
        ->and($items[0]['name'])->toBe('بيتزا مارجريتا')
        ->and($items[0]['quantity'])->toBe(2)
        ->and((float) $items[0]['price'])->toEqual(150.0)
        ->and($items[0]['attributes'])->toHaveKey('الحجم')
        ->and($items[0]['attributes'])->not->toHaveKey('_hidden_meta')
        ->and($items[0]['platform_item_id'])->toBe(101)
        ->and($items[1]['index'])->toBe(2)
        ->and($items[1]['variant'])->toBe('Variation #52');
});

test('OrderEditService derives unit price from subtotal or total when price is zero or not explicit', function () {
    $service = new OrderEditService;

    $order = new EcommerceOrder;
    $order->platform = 'woocommerce';
    $order->raw_payload = [
        'line_items' => [
            [
                'id' => 103,
                'name' => 'عصير مانجو',
                'quantity' => 3,
                'subtotal' => '150.00',
                'total' => '150.00',
            ],
        ],
    ];

    $items = $service->extractLineItems($order);

    expect($items)->toHaveCount(1)
        ->and((float) $items[0]['price'])->toEqual(50.0);
});

test('OrderEditService extracts Shopify line items properly', function () {
    $service = new OrderEditService;

    $order = new EcommerceOrder;
    $order->platform = 'shopify';
    $order->raw_payload = [
        'line_items' => [
            [
                'id' => 99991,
                'title' => 'تيشيرت قطن',
                'variant_title' => 'أسود / L',
                'product_id' => 777,
                'variant_id' => 888,
                'quantity' => 3,
                'price' => '250.00',
                'sku' => 'TSHIRT-BLK-L',
                'properties' => [
                    ['name' => 'طباعة مخصصة', 'value' => 'اسم الشركة'],
                ],
            ],
        ],
    ];

    $items = $service->extractLineItems($order);

    expect($items)->toHaveCount(1)
        ->and($items[0]['index'])->toBe(1)
        ->and($items[0]['name'])->toBe('تيشيرت قطن')
        ->and($items[0]['variant'])->toBe('أسود / L')
        ->and($items[0]['quantity'])->toBe(3)
        ->and($items[0]['price'])->toBe('250.00')
        ->and($items[0]['attributes'])->toBe(['طباعة مخصصة' => 'اسم الشركة'])
        ->and($items[0]['platform_item_id'])->toBe(99991);
});

test('OrderEditService extracts Custom platform line items properly', function () {
    $service = new OrderEditService;

    $order = new EcommerceOrder;
    $order->platform = 'custom';
    $order->raw_payload = [
        'items' => [
            [
                'id' => 'custom_item_1',
                'name' => 'وجبة عائلية',
                'quantity' => 1,
                'price' => 350,
                'attributes' => [
                    'المشروب' => 'بيبسي',
                ],
            ],
        ],
    ];

    $items = $service->extractLineItems($order);

    expect($items)->toHaveCount(1)
        ->and($items[0]['index'])->toBe(1)
        ->and($items[0]['name'])->toBe('وجبة عائلية')
        ->and($items[0]['quantity'])->toBe(1)
        ->and($items[0]['price'])->toBe(350)
        ->and($items[0]['attributes'])->toBe(['المشروب' => 'بيبسي'])
        ->and($items[0]['platform_item_id'])->toBe('custom_item_1');
});

test('OrderEditService formats numbered items list message properly', function () {
    $service = new OrderEditService;

    $order = new EcommerceOrder;
    $order->order_number = '1045';
    $order->total_price = 390.0;
    $order->currency = 'EGP';

    $items = [
        [
            'index' => 1,
            'name' => 'بيتزا مارجريتا',
            'quantity' => 2,
            'price' => 150,
            'variant' => null,
            'attributes' => ['الحجم' => 'كبير'],
        ],
        [
            'index' => 2,
            'name' => 'برجر دجاج',
            'quantity' => 1,
            'price' => 90,
            'variant' => 'سبايسي',
            'attributes' => [],
        ],
    ];

    $message = $service->formatItemsListMessage($items, $order);

    expect($message)->toContain('*1.* بيتزا مارجريتا')
        ->and($message)->toContain('📦 الكمية: 2')
        ->and($message)->toContain('💰 السعر: 150 EGP')
        ->and($message)->toContain('🏷️ الحجم: كبير')
        ->and($message)->toContain('*2.* برجر دجاج (سبايسي)')
        ->and($message)->toContain('💵 *الإجمالي: 390 EGP*')
        ->and($message)->toContain('✏️ *اكتب التعديل المطلوب*');
});

test('OrderEditService builds updated summary, error, and clarification messages', function () {
    $service = new OrderEditService;

    $order = new EcommerceOrder;
    $order->order_number = '2099';
    $order->currency = 'SAR';

    $summary = $service->buildUpdatedOrderSummary($order, ['new_total' => 450], 'تم تعديل كمية الصنف 1 إلى 3');
    expect($summary)->toContain('تم تعديل طلبك رقم #2099 بنجاح')
        ->and($summary)->toContain('تم تعديل كمية الصنف 1 إلى 3')
        ->and($summary)->toContain('450 SAR');

    $error = $service->buildEditErrorMessage($order, 'المنتج غير متاح');
    expect($error)->toContain('لم نتمكن من تعديل طلبك رقم #2099')
        ->and($error)->toContain('المنتج غير متاح');

    $clarification = $service->buildClarificationMessage($order);
    expect($clarification)->toContain('لم أفهم التعديل المطلوب')
        ->and($clarification)->toContain('إلغاء التعديل');
});

test('OrderEditService normalizes attributes replacement or quantity edits into replace_item', function () {
    $service = new OrderEditService;

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('validateEditInstructions');
    $method->setAccessible(true);

    $items = [
        ['index' => 1, 'name' => 'منتج جديد', 'quantity' => 3, 'price' => 99, 'platform_item_id' => 6],
    ];

    // Case 1: AI put product replacement inside update_attributes
    $edits = [
        [
            'action' => 'update_attributes',
            'item_index' => 1,
            'attributes' => [
                'النوع' => 'فستان',
                'الكمية' => 2,
            ],
        ],
    ];

    $result = $method->invoke($service, $edits, $items);

    expect($result)->toHaveCount(1)
        ->and($result[0]['action'])->toBe('replace_item')
        ->and($result[0]['item_index'])->toBe(1)
        ->and($result[0]['product_name'])->toBe('فستان')
        ->and($result[0]['quantity'])->toBe(2);

    // Case 2: AI returned explicit replace_item
    $edits2 = [
        [
            'action' => 'replace_item',
            'item_index' => 1,
            'product_name' => 'فستان',
            'quantity' => 2,
        ],
    ];

    $result2 = $method->invoke($service, $edits2, $items);
    expect($result2)->toHaveCount(1)
        ->and($result2[0]['action'])->toBe('replace_item')
        ->and($result2[0]['product_name'])->toBe('فستان')
        ->and($result2[0]['quantity'])->toBe(2);
});

test('OrderEditService remaps outdated line item IDs to fresh live WooCommerce item IDs', function () {
    $service = new OrderEditService;

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('remapWooCommerceItemUpdates');
    $method->setAccessible(true);

    $oldItems = [
        [
            'index' => 1,
            'name' => 'منتج جديد',
            'product_id' => 15,
            'platform_item_id' => 6, // old ID
            'quantity' => 3,
            'price' => 99,
        ],
    ];

    $freshLineItems = [
        [
            'id' => 8, // new live ID on WooCommerce
            'name' => 'منتج جديد',
            'product_id' => 15,
            'quantity' => 3,
            'total' => '297.00',
        ],
    ];

    $updates = [
        [
            'id' => 6,
            'quantity' => 2,
            'subtotal' => '198.00',
            'total' => '198.00',
        ],
    ];

    $remapped = $method->invoke($service, $updates, $oldItems, $freshLineItems);

    expect($remapped)->toHaveCount(1)
        ->and($remapped[0]['id'])->toBe(8)
        ->and($remapped[0]['quantity'])->toBe(2)
        ->and($remapped[0]['total'])->toBe('198.00');
});

test('OrderEditService detects fast-path confirmation intent properly', function () {
    $service = new OrderEditService;

    expect($service->isConfirmationIntent('تأكيد'))->toBeTrue()
        ->and($service->isConfirmationIntent('تمام'))->toBeTrue()
        ->and($service->isConfirmationIntent('كده تمام'))->toBeTrue()
        ->and($service->isConfirmationIntent('اعتمد يا باشا'))->toBeTrue()
        ->and($service->isConfirmationIntent('اوكي'))->toBeTrue()
        ->and($service->isConfirmationIntent('عاوز قطعتين فقط'))->toBeFalse()
        ->and($service->isConfirmationIntent('مكانكم فين؟'))->toBeFalse();
});

test('OrderEditService builds final confirmation message properly', function () {
    $service = new OrderEditService;

    $order = new EcommerceOrder;
    $order->order_number = '23';
    $order->total_price = 198.0;
    $order->currency = 'EGP';
    $order->edit_items_snapshot = [
        ['index' => 1, 'name' => 'منتج جديد', 'quantity' => 2, 'price' => 99, 'attributes' => []],
    ];

    $message = $service->buildFinalConfirmationMessage($order);

    expect($message)->toContain('تم تأكيد طلبك رقم #23 بنجاح')
        ->and($message)->toContain('*1.* منتج جديد')
        ->and($message)->toContain('📦 الكمية: 2')
        ->and($message)->toContain('💵 *الإجمالي النهائي: 198 EGP*')
        ->and($message)->toContain('جارٍ تجهيز طلبك');
});
