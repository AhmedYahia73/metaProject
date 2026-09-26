<?php

namespace App\trait;

use Almesery\LaravelGeidea\Facades\Geidea;
use Almesery\LaravelGeidea\Facades\Geidea as GeideaFacade;
use App\Http\Resources\AddonResource;
use App\Http\Resources\ExcludeResource;
use App\Http\Resources\ExtraResource;
use App\Http\Resources\OptionResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\VariationResource;
use App\Models\Address;
use App\Models\BranchOff;
use App\Models\Geidia;
use App\Models\Kitchen;
use App\Models\KitchenItem;
use App\Models\KItemAddon;
use App\Models\KItemExclude;
use App\Models\KItemExtra;
use App\Models\KItemOption;
use App\Models\KItemVriation;
use App\Models\Order;
use App\Models\OrderCartBOption;
use App\Models\OrderCartBundle;
use App\Models\OrderCartBVariation;
use App\Models\Payment;
use App\Models\ProductCart;
use App\Models\Setting;
use App\Models\TranslationTbl;
use Illuminate\Http\Exceptions\HttpResponseException;

trait PlaceOrder
{
    // This Traite About Place Order
    protected $paymentRequest = [
        'date',
        'branch_id',
        'amount',
        'total_tax',
        'total_discount',
        'address_id',
        'branch_id',
        'order_type',
        'payment_method_id',
        'notes',
        'coupon_discount',
        'sechedule_slot_id',
        'source',
        'captain_id',
        'service_fees',
        'service_fees_id',
        'delivery_fees',
        'time_start',
        'time_end',
    ];

    protected $orderRequest = ['user_id', 'cart'];

    protected $priceCycle;

    public function placeOrder($request, $user)
    {

        // Start Make Payment
        $paymentRequest = $request->only($this->paymentRequest);
        try {
            $activePaymentMethod = $this->paymentMethod->where('status', '1')->find($paymentRequest['payment_method_id']);
            if (! $activePaymentMethod) {
                return response()->json([
                    'paymentMethod.message' => 'This Payment Method Unavailable ',
                ], 404);
            }
            $order = $this->make_order($request, 1);
            if (isset($order['errors']) && ! empty($order['errors'])) {
                return $order;
            }
        } catch (\Throwable $th) {
            throw new HttpResponseException(response()->json(['errors' => 'Payment processing failed'], 500));
        }
        // End Make Payment

        return [
            'payment' => $order['payment'],
            'orderItems' => $order['orderItems'],
            'items' => $order['items'],
        ];
    }

    private function createOrdersForItems(array $items, string $field, array $baseData)
    {

        $createdOrders = [];
        $count = 1;
        foreach ($items as $item) {
            // Ensure $item is an array
            // return $items;
            if (! is_array($item)) {
                throw new \InvalidArgumentException('Each item should be an array.');
            }
            $periodPrice = $item['price_cycle'];

            // Determine the model based on the $field
            $itemName = match ($field) {
                'extra_id' => 'extra',
                'domain_id' => 'domain',
                'plan_id' => 'plan',
                default => throw new \InvalidArgumentException("Invalid field provided: $field"),
            };
            $model = $this->$itemName->find($item[$field]);
            $this->priceCycle = $model->$periodPrice ?? $model->price;
            // Prepare the order data

            $orderData = array_merge($baseData, [
                $field => $item[$field],
                'price_cycle' => $periodPrice, // Add price_cycle here
                'price_item' => $this->priceCycle, // Add price_item here
            ]);

            // Validate if item has the field key
            if (! isset($item[$field])) {
                throw new \InvalidArgumentException("Missing $field key in item.");
            }
            // Create the order and retrieve the model
            $createdOrder = $this->order->create($orderData);
            // Prepare the item data
            $itemData = [
                'name' => $model->name,
                'amount_cents' => $this->priceCycle ?? $model->price,
                'period' => $item['price_cycle'],
                'quantity' => $count,
                'description' => "Your Item is $model->name and Price: ".$this->priceCycle ?? $model->price,
            ];

            $createdOrders[] = $itemData;
        }

        return $createdOrders;
    }

    public function payment_approve($payment)
    {
        if ($payment) {
            $payment->update(['status' => 1]);

            return true;
        }

        return false;
    }

    public function order_success($payment) {}

    public function make_order($request, $paymob = 0)
    {
        $branch_off = BranchOff::where('branch_id', $request->branch_id)
            ->get();
        $products_off = $branch_off->pluck('product_id')->filter()->values()->all();
        $options_off = $branch_off->pluck('option_id')->filter()->values()->all();
        $categories_off = $branch_off->pluck('category_id')->filter()->values()->all();
        $orderRequest = $request->only($this->paymentRequest);
        $user = auth()->user();

        if (! $request->user_id || $request->user_id != 'empty') {
            $orderRequest['user_id'] = auth()->user()->id;
        }

        if (! empty($request->customer_id) && is_numeric($request->customer_id)) {
            $orderRequest['customer_id'] = $request->customer_id;
        }

        $orderRequest['order_status'] = 'pending';
        if ($request->table_id) {
            $orderRequest['table_id'] = $request->table_id;
        }
        if ($request->captain_id) {
            $orderRequest['captain_id'] = $request->captain_id;
        }
        if ($request->cashier_id) {
            $orderRequest['cashier_id'] = $request->cashier_id;
        }
        if ($request->cashier_man_id) {
            $orderRequest['cashier_man_id'] = $request->cashier_man_id;
        }
        if ($request->shift) {
            $orderRequest['shift'] = $request->shift;
        }
        $locale = $request->locale ?? $request->query('locale', app()->getLocale()); // Get Local Translation
        $points = 0;
        $items = [];
        $order_details = [];
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $product) {
                $item = $this->products
                    ->where('id', $product['product_id'])
                    ->first();
                if (in_array($item->id, $products_off) ||
                in_array($item->category_id, $categories_off) ||
                in_array($item->sub_category_id, $categories_off)) {
                    return [
                        'errors' => 'Product '.$item->name.
                        ' is not found at this branch you can change branch or order',
                    ];
                }
                if (! empty($item)) {
                    $items[] = ['name' => $item->name,
                        'amount_cents' => (int) round($item->price * 100),
                        'description' => $item->description,
                        'quantity' => $product['count'],
                    ];
                    $points += $item->points * $product['count'];
                    if (isset($product['variation'])) {
                        foreach ($product['variation'] as $variation) {
                            if ($variation['option_id']) {
                                foreach ($variation['option_id'] as $option_id) {
                                    $option_points = $this->options
                                        ->where('id', $option_id)
                                        ->first();
                                    if (in_array($option_points->id, $options_off)) {
                                        return [
                                            'errors' => 'Option '.$option_points->name.' at product '.$item->name.
                                            ' is not found at this branch you can change branch or order',
                                        ];
                                    }
                                    $points += $option_points->points * $product['count'];
                                }
                            }
                        }
                    }
                }
            }
        }
        if ($request->receipt) {
            $orderRequest['receipt'] = $request->receipt;
        }
        $orderRequest['points'] = $points;
        $order = $this->order
            ->create($orderRequest);
        if (! empty($user)) {
            $user->save();
        }
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $key => $product) {
                // $amount_product = 0;
                $order_details[$key]['extras'] = [];
                $order_details[$key]['addons'] = [];
                $order_details[$key]['excludes'] = [];
                $order_details[$key]['product'] = [];
                $order_details[$key]['variations'] = [];

                $product_item = $this->products
                    ->where('id', $product['product_id'])
                    ->first();
                $product_item = collect([$product_item]);
                $product_item = ProductResource::collection($product_item);
                $product_item = count($product_item) > 0 ? $product_item[0] : null;
                $order_details[$key]['product'][] = [
                    'product' => $product_item,
                    'count' => $product['count'],
                    'notes' => isset($product['note']) ? $product['note'] : null,
                ];
                // Add product price
                // $amount_product += $product_item->price;

                $this->order_details
                    ->create([
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'count' => $product['count'],
                        'product_index' => $key,
                    ]); // Add product with count
                if (isset($product['exclude_id'])) {
                    foreach ($product['exclude_id'] as $exclude) {
                        $this->order_details
                            ->create([
                                'order_id' => $order->id,
                                'product_id' => $product['product_id'],
                                'exclude_id' => $exclude,
                                'count' => $product['count'],
                                'product_index' => $key,
                            ]); // Add excludes

                        $exclude = $this->excludes
                            ->where('id', $exclude)
                            ->first();
                        $exclude = collect([$exclude]);
                        $exclude = ExcludeResource::collection($exclude);
                        $exclude = count($exclude) > 0 ? $exclude[0] : null;
                        $order_details[$key]['excludes'][] = $exclude;
                    }
                }
                if (isset($product['addons'])) {
                    foreach ($product['addons'] as $addon) {
                        $this->order_details
                            ->create([
                                'order_id' => $order->id,
                                'product_id' => $product['product_id'],
                                'addon_id' => $addon['addon_id'],
                                'count' => $product['count'],
                                'addon_count' => $addon['count'],
                                'product_index' => $key,
                            ]); // Add excludes

                        $addon_item = $this->addons
                            ->where('id', $addon['addon_id'])
                            ->first();
                        $addon_item = collect([$addon_item]);
                        $addon_item = AddonResource::collection($addon_item);
                        $addon_item = count($addon_item) > 0 ? $addon_item[0] : null;
                        $order_details[$key]['addons'][] = [
                            'addon' => $addon_item,
                            'count' => $addon['count'],
                        ];
                    }
                }
                if (isset($product['extra_id'])) {
                    foreach ($product['extra_id'] as $extra) {
                        $this->order_details
                            ->create([
                                'order_id' => $order->id,
                                'product_id' => $product['product_id'],
                                'extra_id' => $extra,
                                'count' => $product['count'],
                                'product_index' => $key,
                            ]); // Add extra
                        $extra_item = $this->extras
                            ->where('id', $extra)
                            ->first();
                        $extra_item = collect([$extra_item]);
                        $extra_item = ExtraResource::collection($extra_item);
                        $extra_item = count($extra_item) > 0 ? $extra_item[0] : null;
                        $order_details[$key]['extras'][] = $extra_item;
                    }
                }
                if (isset($product['product_extra_id'])) {
                    foreach ($product['product_extra_id'] as $extra) {
                        $this->order_details
                            ->create([
                                'order_id' => $order->id,
                                'product_id' => $product['product_id'],
                                'extra_id' => $extra,
                                'count' => $product['count'],
                                'product_index' => $key,
                            ]); // Add extra

                        $extra_item = $this->extras
                            ->where('id', $extra)
                            ->first();
                        $extra_item = collect([$extra_item]);
                        $extra_item = ExtraResource::collection($extra_item);
                        $extra_item = count($extra_item) > 0 ? $extra_item[0] : null;
                        $order_details[$key]['extras'][] = $extra_item;
                    }
                }
                if (isset($product['variation'])) {
                    $product['variation'] = collect($product['variation'])->unique('variation_id');
                    foreach ($product['variation'] as $variation) {
                        foreach ($variation['option_id'] as $option_id) {
                            $this->order_details
                                ->create([
                                    'order_id' => $order->id,
                                    'product_id' => $product['product_id'],
                                    'variation_id' => $variation['variation_id'],
                                    'option_id' => $option_id,
                                    'count' => $product['count'],
                                    'product_index' => $key,
                                ]); // Add variations & options
                        }
                        $variations = $this->variation
                            ->where('id', $variation['variation_id'])
                            ->first();
                        $variations = collect([$variations]);
                        $options = $this->options
                            ->whereIn('id', $variation['option_id'])
                            ->get();
                        $variations = VariationResource::collection($variations);
                        $variations = count($variations) > 0 ? $variations[0] : null;
                        $options = OptionResource::collection($options);
                        $order_details[$key]['variations'][] = [
                            'variation' => $variations,
                            'options' => $options,
                        ];
                        // $amount_product += $this->options
                        // ->whereIn('id', $variation['option_id'])
                        // ->sum('price');
                    }
                }
                $discount_item = $product_item->discount;
                $tax_item = $product_item->tax;
                $tax = $this->settings
                    ->where('name', 'tax')
                    ->orderByDesc('created_at')
                    ->first();
                if (! empty($tax_item)) {
                    if (! empty($tax)) {
                        $tax = $tax->setting;
                    } else {
                        $tax = $this->settings
                            ->create([
                                'name' => 'tax',
                                'setting' => 'included',
                            ]);
                        $tax = $tax->setting;
                    }
                    // if ($tax_item->type == 'precentage') {
                    //     $amount_product = $amount_product + $amount_product * $tax_item->amount / 100;
                    // }
                    // else{
                    //     $amount_product = $amount_product + $tax_item->amount;
                    // }
                }
                // if (!empty($discount_item)) {
                //     if ($discount_item->type == 'precentage') {
                //         $amount_product = $amount_product - $amount_product * $discount_item->amount / 100;
                //     }
                //     else{
                //         $amount_product = $amount_product - $discount_item->amount;
                //     }
                // }
            }
        }
        $order->order_details = json_encode($order_details);
        $order->load('payment_method.geidea');
        $gedia_status = false;
        $gedia = null;
        if ($paymob) {
            $order->status = 2;
            $order->save();
        }
        if (! empty($order->payment_method?->geidea)) {
            try {
                $order->status = 2; // hide order until payment confirmed
                $order->save();
                $gedia = $this->geidea($order->id, $order->amount);
                if (isset($gedia['error'])) {
                    \Log::error('Geidea error: '.$gedia['error']);
                    $gedia = null;
                    $gedia_status = false;
                } else {
                    $gedia_status = isset($gedia['session_id']);
                }
            } catch (\Exception $e) {
                \Log::error('Geidea exception: '.$e->getMessage());
                $gedia = null;
                $gedia_status = false;
            }
        }
        $order->save();

        return [
            'payment' => $order,
            'orderItems' => $order_details,
            'items' => $items,
            'gedia' => $gedia,
            'gedia_status' => $gedia_status,
        ];
    }

    public function geidea($id, $amount)
    {

        $settings = Geidia::first();

        if (! $settings) {
            return ['error' => 'Geidea settings not found'];
        }

        // ✅ غيّر الـ config في runtime
        config([
            'geidea.merchant_public_key' => $settings->geidea_public_key,
            'geidea.api_password' => $settings->api_password,
            'geidea.environment' => $settings->environment,
            'geidea.currency' => 'EGP',
            'geidea.language' => 'ar',
        ]);

        try {
            // ✅ استخدم الـ Facade عادي
            $result = GeideaFacade::createSession([
                'amount' => $amount,
                'currency' => 'EGP',
                'merchant_reference_id' => geidea_merchant_reference('ORDER', $id),
                'callback_url' => url('/customer/geidia/callback'),
                'return_url' => url('/customer/geidia/return'),
                'customer' => [
                    'email' => auth()->user()->email,
                    'name' => auth()->user()->f_name.' '.auth()->user()->l_name,
                    'phone_number' => auth()->user()->phone,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Geidea createSession failed: '.$e->getMessage());

            return ['error' => 'Failed to create payment session: '.$e->getMessage()];
        }

        if (! $result['success']) {
            return ['error' => $result['message'] ?? 'Unknown error'];
        }

        // Geidea doesn't return order_id in session creation, only in callback
        // We'll use the merchant_reference_id for now
        $merchantReferenceId = $result['data']['session']['merchantReferenceId'] ?? null;

        // Save session_id as transaction_id to find the order in return_page
        Order::where('id', $id)->update([
            'transaction_id' => $result['session_id'],
        ]);

        // ✅ إرجاع البيانات للـ Frontend ليعرض صفحة الدفع مباشرة
        return [
            'session_id' => $result['session_id'],
            'merchant_key' => $settings->geidea_public_key,
            'merchant_reference_id' => $merchantReferenceId,
            'hpp_url' => 'https://www.merchant.geidea.net/hpp/geideaCheckout.min.js',
        ];
    }

    public function make_order_cart($request, $paymob = 0)
    {
        $branch_off = BranchOff::where('branch_id', $request->branch_id)
            ->get();
        $products_off = $branch_off->pluck('product_id')->filter()->values()->all();
        $options_off = $branch_off->pluck('option_id')->filter()->values()->all();
        $categories_off = $branch_off->pluck('category_id')->filter()->values()->all();
        $orderRequest = $request->only($this->paymentRequest);
        $user = auth()->user();
        if ($request->table_id) {
            $orderRequest['table_id'] = $request->table_id;
        }
        if ($request->captain_id) {
            $orderRequest['captain_id'] = $request->captain_id;
        }
        if ($request->cashier_id) {
            $orderRequest['cashier_id'] = $request->cashier_id;
        }
        if ($request->cashier_man_id) {
            $orderRequest['cashier_man_id'] = $request->cashier_man_id;
        }
        if ($request->shift) {
            $orderRequest['shift'] = $request->shift;
        }
        $locale = $request->locale ?? $request->query('locale', app()->getLocale()); // Get Local Translation
        $points = 0;
        $items = [];
        $order_details = [];
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $product) {
                $item = $this->products
                    ->where('id', $product['product_id'])
                    ->first();
                if (in_array($item->id, $products_off) ||
                in_array($item->category_id, $categories_off) ||
                in_array($item->sub_category_id, $categories_off)) {
                    return [
                        'errors' => 'Product '.$item->name.
                        ' is not found at this branch you can change branch or order',
                    ];
                }
                if (! empty($item)) {
                    $items[] = ['name' => $item->name,
                        'amount_cents' => $item->price,
                        'description' => $item->description,
                        'quantity' => $product['count'],
                    ];
                    $points += $item->points * $product['count'];
                    if (isset($product['variation'])) {
                        foreach ($product['variation'] as $variation) {
                            if ($variation['option_id']) {
                                foreach ($variation['option_id'] as $option_id) {
                                    $option_points = $this->options
                                        ->where('id', $option_id)
                                        ->first();
                                    if (in_array($option_points->id, $options_off)) {
                                        return [
                                            'errors' => 'Option '.$option_points->name.' at product '.$item->name.
                                            ' is not found at this branch you can change branch or order',
                                        ];
                                    }
                                    $points += $option_points->points * $product['count'];
                                }
                            }
                        }
                    }
                }
            }
        }
        if ($request->order_pending) {
            $orderRequest['order_active'] = 0;
        }

        $orderRequest['points'] = $points;
        $order = $this->order_cart
            ->create($orderRequest);
        if (! empty($user)) {
            $user->save();
        }
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $key => $product) {
                // $amount_product = 0;
                $order_details[$key]['extras'] = [];
                $order_details[$key]['addons'] = [];
                $order_details[$key]['excludes'] = [];
                $order_details[$key]['product'] = [];
                $order_details[$key]['variations'] = [];

                $product_item = $this->products
                    ->where('id', $product['product_id'])
                    ->withLocale($locale)
                    ->first();
                $product_item = collect([$product_item]);
                $product_item = ProductResource::collection($product_item);
                $product_item = count($product_item) > 0 ? $product_item[0] : null;
                $order_details[$key]['product'][] = [
                    'product' => $product_item,
                    'count' => $product['count'],
                    'prepration' => 'watting',
                    'notes' => isset($product['note']) ? $product['note'] : null,
                ];
                // Add product price
                // $amount_product += $product_item->price;

                // $this->order_details
                // ->create([
                //     'order_id' => $order->id,
                //     'product_id' => $product['product_id'],
                //     'count' => $product['count'],
                //     'product_index' => $key,
                // ]); // Add product with count
                $exclude_ids = $product['exclude_id'] ?? $product['excludes'] ?? $product['exclude'] ?? null;
                if (isset($exclude_ids) && (is_array($exclude_ids) || is_object($exclude_ids))) {
                    foreach ($exclude_ids as $exclude) {
                        $exId = is_array($exclude) ? ($exclude['id'] ?? null) : (is_object($exclude) ? ($exclude->id ?? null) : $exclude);
                        if (! empty($exId)) {
                            $exclude_item = $this->excludes
                                ->where('id', $exId)
                                ->withLocale($locale)
                                ->first();
                            if ($exclude_item) {
                                $exclude_coll = collect([$exclude_item]);
                                $exclude_res = ExcludeResource::collection($exclude_coll);
                                $order_details[$key]['excludes'][] = count($exclude_res) > 0 ? $exclude_res[0] : null;
                            }
                        }
                    }
                }
                if (isset($product['addons'])) {
                    foreach ($product['addons'] as $addon) {
                        // $this->order_details
                        // ->create([
                        //     'order_id' => $order->id,
                        //     'product_id' => $product['product_id'],
                        //     'addon_id' => $addon['addon_id'],
                        //     'count' => $product['count'],
                        //     'addon_count' => $addon['count'],
                        //     'product_index' => $key,
                        // ]); // Add excludes

                        $addon_item = $this->addons
                            ->where('id', $addon['addon_id'])
                            ->withLocale($locale)
                            ->first();
                        $addon_item = collect([$addon_item]);
                        $addon_item = AddonResource::collection($addon_item);
                        $addon_item = count($addon_item) > 0 ? $addon_item[0] : null;
                        $order_details[$key]['addons'][] = [
                            'addon' => $addon_item,
                            'count' => $addon['count'],
                        ];
                    }
                }
                if (isset($product['extra_id'])) {
                    foreach ($product['extra_id'] as $extra) {
                        // $this->order_details
                        // ->create([
                        //     'order_id' => $order->id,
                        //     'product_id' => $product['product_id'],
                        //     'extra_id' => $extra,
                        //     'count' => $product['count'],
                        //     'product_index' => $key,
                        // ]); // Add extra
                        $extra_item = $this->extras
                            ->where('id', $extra)
                            ->withLocale($locale)
                            ->first();
                        $extra_item = collect([$extra_item]);
                        $extra_item = ExtraResource::collection($extra_item);
                        $extra_item = count($extra_item) > 0 ? $extra_item[0] : null;
                        $order_details[$key]['extras'][] = $extra_item;
                    }
                }
                if (isset($product['product_extra_id'])) {
                    foreach ($product['product_extra_id'] as $extra) {
                        // $this->order_details
                        // ->create([
                        //     'order_id' => $order->id,
                        //     'product_id' => $product['product_id'],
                        //     'extra_id' => $extra,
                        //     'count' => $product['count'],
                        //     'product_index' => $key,
                        // ]); // Add extra

                        $extra_item = $this->extras
                            ->where('id', $extra)
                            ->withLocale($locale)
                            ->first();
                        $extra_item = collect([$extra_item]);
                        $extra_item = ExtraResource::collection($extra_item);
                        $extra_item = count($extra_item) > 0 ? $extra_item[0] : null;
                        $order_details[$key]['extras'][] = $extra_item;
                    }
                }
                if (isset($product['variation'])) {
                    foreach ($product['variation'] as $variation) {
                        // foreach ($variation['option_id'] as $option_id) {
                        //     $this->order_details
                        //     ->create([
                        //         'order_id' => $order->id,
                        //         'product_id' => $product['product_id'],
                        //         'variation_id' => $variation['variation_id'],
                        //         'option_id' => $option_id,
                        //         'count' => $product['count'],
                        //         'product_index' => $key,
                        //     ]); // Add variations & options
                        // }
                        $variations = $this->variation
                            ->where('id', $variation['variation_id'])
                            ->withLocale($locale)
                            ->first();
                        $variations = collect([$variations]);
                        $options = $this->options
                            ->whereIn('id', $variation['option_id'])
                            ->withLocale($locale)
                            ->get();
                        $variations = VariationResource::collection($variations);
                        $variations = count($variations) > 0 ? $variations[0] : null;
                        $options = OptionResource::collection($options);
                        $order_details[$key]['variations'][] = [
                            'variation' => $variations,
                            'options' => $options,
                        ];
                        // $order_details[$key]['excludes'] = [];
                        // $order_details[$key]['variations'] = [];
                        // $amount_product += $this->options
                        // ->whereIn('id', $variation['option_id'])
                        // ->sum('price');
                    }
                }
                // tax handled via get_tax_setting() cache

            }
        }
        if (isset($request->bundles)) {
            foreach ($request->bundles as $bundle_item) {
                $order_cart_b = OrderCartBundle::create([
                    'bundle_id' => $bundle_item['id'],
                    'order_cart_id' => $order->id,
                    'count' => $bundle_item['count'],
                ]);
                foreach ($bundle_item['variation'] as $variation_element) {
                    $order_variation = OrderCartBVariation::create([
                        'order_cart_id' => $order->id,
                        'variation_id' => $variation_element['id'],
                        'order_cart_b_id' => $order_cart_b->id,
                    ]);
                    foreach ($variation_element['options'] as $option_element) {
                        OrderCartBOption::create([
                            'order_cart_id' => $order->id,
                            'variation_bundle_id' => $order_variation->id,
                            'option_id' => $option_element,
                        ]);
                    }
                }
            }
        }
        $order->cart = json_encode($order_details);
        if ($request->order_status) {
            $order->prepration_status = $request->order_status;
        }
        $order->save();

        return [
            'payment' => $order,
            'orderItems' => $order_details,
            'items' => $items,
        ];
    }

    public function make_order_multi_cart($request, $paymob = 0)
    {
        $branch_off = BranchOff::where('branch_id', $request->branch_id)
            ->get();
        $ids = [];
        $products_off = $branch_off->pluck('product_id')->filter()->values()->all();
        $options_off = $branch_off->pluck('option_id')->filter()->values()->all();
        $categories_off = $branch_off->pluck('category_id')->filter()->values()->all();
        $orderRequest = $request->only($this->paymentRequest);
        $user = auth()->user();
        if ($request->table_id) {
            $orderRequest['table_id'] = $request->table_id;
        }
        if ($request->captain_id) {
            $orderRequest['captain_id'] = $request->captain_id;
        }
        if ($request->cashier_id) {
            $orderRequest['cashier_id'] = $request->cashier_id;
        }
        if ($request->cashier_man_id) {
            $orderRequest['cashier_man_id'] = $request->cashier_man_id;
        }
        if ($request->shift) {
            $orderRequest['shift'] = $request->shift;
        }
        $locale = $request->locale ?? $request->query('locale', app()->getLocale()); // Get Local Translation
        $points = 0;
        $items = [];
        $order_details = [];
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $product) {
                $item = $this->products
                    ->where('id', $product['product_id'])
                    ->first();
                if (in_array($item->id, $products_off) ||
                in_array($item->category_id, $categories_off) ||
                in_array($item->sub_category_id, $categories_off)) {
                    return [
                        'errors' => 'Product '.$item->name.
                        ' is not found at this branch you can change branch or order',
                    ];
                }
                if (! empty($item)) {
                    $items[] = ['name' => $item->name,
                        'amount_cents' => $item->price,
                        'description' => $item->description,
                        'quantity' => $product['count'],
                    ];
                    $points += $item->points * $product['count'];
                    if (isset($product['variation'])) {
                        foreach ($product['variation'] as $variation) {
                            if ($variation['option_id']) {
                                foreach ($variation['option_id'] as $option_id) {
                                    $option_points = $this->options
                                        ->where('id', $option_id)
                                        ->first();
                                    if (in_array($option_points->id, $options_off)) {
                                        return [
                                            'errors' => 'Option '.$option_points->name.' at product '.$item->name.
                                            ' is not found at this branch you can change branch or order',
                                        ];
                                    }
                                    $points += $option_points->points * $product['count'];
                                }
                            }
                        }
                    }
                }
            }
        }
        if ($request->order_pending) {
            $orderRequest['order_active'] = 0;
        }

        $orderRequest['points'] = $points;
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $key => $product) {
                // $amount_product = 0;
                $order = $this->order_cart
                    ->create($orderRequest);
                if (! empty($user)) {
                    $user->save();
                }
                $order_details[0]['extras'] = [];
                $order_details[0]['addons'] = [];
                $order_details[0]['excludes'] = [];
                $order_details[0]['product'] = [];
                $order_details[0]['variations'] = [];

                $product_item = $this->products
                    ->where('id', $product['product_id'])
                    ->withLocale($locale)
                    ->first();
                $product_item = collect([$product_item]);
                $product_item = ProductResource::collection($product_item);
                $product_item = count($product_item) > 0 ? $product_item[0] : null;
                $order_details[0]['product'][] = [
                    'product' => $product_item,
                    'count' => $product['count'],
                    'prepration' => 'watting',
                    'notes' => isset($product['note']) ? $product['note'] : null,
                ];
                if (isset($product['exclude_id'])) {
                    foreach ($product['exclude_id'] as $exclude) {

                        $exclude = $this->excludes
                            ->where('id', $exclude)
                            ->withLocale($locale)
                            ->first();
                        $exclude = collect([$exclude]);
                        $exclude = ExcludeResource::collection($exclude);
                        $exclude = count($exclude) > 0 ? $exclude[0] : null;
                        $order_details[0]['excludes'][] = $exclude;
                    }
                }
                if (isset($product['addons'])) {
                    foreach ($product['addons'] as $addon) {
                        // $this->order_details
                        // ->create([
                        //     'order_id' => $order->id,
                        //     'product_id' => $product['product_id'],
                        //     'addon_id' => $addon['addon_id'],
                        //     'count' => $product['count'],
                        //     'addon_count' => $addon['count'],
                        //     'product_index' => $key,
                        // ]); // Add excludes

                        $addon_item = $this->addons
                            ->where('id', $addon['addon_id'])
                            ->withLocale($locale)
                            ->first();
                        $addon_item = collect([$addon_item]);
                        $addon_item = AddonResource::collection($addon_item);
                        $addon_item = count($addon_item) > 0 ? $addon_item[0] : null;
                        $order_details[0]['addons'][] = [
                            'addon' => $addon_item,
                            'count' => $addon['count'],
                        ];
                    }
                }
                if (isset($product['extra_id'])) {
                    foreach ($product['extra_id'] as $extra) {
                        // $this->order_details
                        // ->create([
                        //     'order_id' => $order->id,
                        //     'product_id' => $product['product_id'],
                        //     'extra_id' => $extra,
                        //     'count' => $product['count'],
                        //     'product_index' => $key,
                        // ]); // Add extra
                        $extra_item = $this->extras
                            ->where('id', $extra)
                            ->withLocale($locale)
                            ->first();
                        $extra_item = collect([$extra_item]);
                        $extra_item = ExtraResource::collection($extra_item);
                        $extra_item = count($extra_item) > 0 ? $extra_item[0] : null;
                        $order_details[0]['extras'][] = $extra_item;
                    }
                }
                if (isset($product['product_extra_id'])) {
                    foreach ($product['product_extra_id'] as $extra) {
                        // $this->order_details
                        // ->create([
                        //     'order_id' => $order->id,
                        //     'product_id' => $product['product_id'],
                        //     'extra_id' => $extra,
                        //     'count' => $product['count'],
                        //     'product_index' => $key,
                        // ]); // Add extra

                        $extra_item = $this->extras
                            ->where('id', $extra)
                            ->withLocale($locale)
                            ->first();
                        $extra_item = collect([$extra_item]);
                        $extra_item = ExtraResource::collection($extra_item);
                        $extra_item = count($extra_item) > 0 ? $extra_item[0] : null;
                        $order_details[0]['extras'][] = $extra_item;
                    }
                }
                if (isset($product['variation'])) {
                    foreach ($product['variation'] as $variation) {
                        // foreach ($variation['option_id'] as $option_id) {
                        //     $this->order_details
                        //     ->create([
                        //         'order_id' => $order->id,
                        //         'product_id' => $product['product_id'],
                        //         'variation_id' => $variation['variation_id'],
                        //         'option_id' => $option_id,
                        //         'count' => $product['count'],
                        //         'product_index' => $key,
                        //     ]); // Add variations & options
                        // }
                        $variations = $this->variation
                            ->where('id', $variation['variation_id'])
                            ->withLocale($locale)
                            ->first();
                        $variations = collect([$variations]);
                        $options = $this->options
                            ->whereIn('id', $variation['option_id'])
                            ->withLocale($locale)
                            ->get();
                        $variations = VariationResource::collection($variations);
                        $variations = count($variations) > 0 ? $variations[0] : null;
                        $options = OptionResource::collection($options);
                        $order_details[0]['variations'][] = [
                            'variation' => $variations,
                            'options' => $options,
                        ];
                        // $order_details[0]['excludes'] = [];
                        // $order_details[0]['variations'] = [];
                        // $amount_product += $this->options
                        // ->whereIn('id', $variation['option_id'])
                        // ->sum('price');
                    }
                }
                // tax handled via get_tax_setting() cache

                $order->cart = json_encode($order_details);
                $order->save();
                $ids[] = $order->id;
            }
        }
        if (isset($request->bundles)) {
            foreach ($request->bundles as $bundle_item) {
                $order_cart_b = OrderCartBundle::create([
                    'bundle_id' => $bundle_item['id'],
                    'order_cart_id' => $order->id,
                    'count' => $bundle_item['count'],
                ]);
                foreach ($bundle_item['variation'] as $variation_element) {
                    $order_variation = OrderCartBVariation::create([
                        'order_cart_id' => $order->id,
                        'variation_id' => $variation_element['id'],
                        'order_cart_b_id' => $order_cart_b->id,
                    ]);
                    foreach ($variation_element['options'] as $option_element) {
                        OrderCartBOption::create([
                            'order_cart_id' => $order->id,
                            'variation_bundle_id' => $order_variation->id,
                            'option_id' => $option_element,
                        ]);
                    }
                }
            }
        }
        if ($request->order_status) {
            $order->prepration_status = $request->order_status;
        }

        return [
            'payment' => $ids,
            'orderItems' => $order_details,
            'items' => $items,
        ];
    }

    public function order_format($order, $locale = 'en')
    {
        $order_data = [];
        foreach ($order->cart ?? $order as $key => $item) {
            if (isset($item->product)) {
                $product = $item->product[0]->product;
                $product->name = TranslationTbl::where('locale', $locale)
                    ->where('key', $product->name)
                    ->orderByDesc('created_at')
                    ->first()->value ?? $product->name;
                $product->description = TranslationTbl::where('locale', $locale)
                    ->where('key', $product->description)
                    ->orderByDesc('created_at')
                    ->first()->value ?? $product->description;
                unset($product->addons);
                unset($product->variations);
                $variation = [];
                $addons = [];
                // $item->addons->addon->count = $item->addons->count;
                // $item->variations->variation->options = $item->variations->options;
                foreach ($item->variations as $key => $element) {
                    $options = [];
                    foreach ($element->options as $value) {
                        $value->name = TranslationTbl::where('locale', $locale)
                            ->where('key', $value->name)
                            ->orderByDesc('created_at')
                            ->first()->value ?? $value->name;
                        $options[] = $value;
                    }
                    $element->variation->options = $options;
                    unset($element->options);
                    $element->variation->name = TranslationTbl::where('locale', $locale)
                        ->where('key', $element->variation->name)
                        ->orderByDesc('created_at')
                        ->first()->value ?? $element->variation->name;
                    $variation[] = $element->variation;
                }
                foreach ($item->addons as $key => $element) {
                    $element->addon->count = $element->count;
                    unset($element->count);
                    $element->addon->name = TranslationTbl::where('locale', $locale)
                        ->where('key', $element->addon->name)
                        ->orderByDesc('created_at')
                        ->first()->value ?? $element->addon->name;
                    $addons[] = $element->addon;
                }
                $order_data[$key] = $product;
                $order_data[$key]->cart_id = $order->id;
                $order_data[$key]->product_index = $key;
                $order_data[$key]->count = $item->product[0]->count;
                $order_data[$key]->prepration = $order->prepration_status ?? $item->product[0]->prepration ?? null;
                $order_data[$key]->excludes = $item->excludes;
                $order_data[$key]->extras = $item->extras;
                $order_data[$key]->variation_selected = $variation;
                $order_data[$key]->addons_selected = $addons;
                $order_data[$key]->bundles = $order->bundles_items;
                $order_data[$key]->time_start = $order->time_start ?? null;
                $order_data[$key]->time_end = $order->time_end ?? null;
                $order_data[$key]->time_ended = ! empty($order->time_end);
                if (! empty($order->time_start) && ! empty($order->time_end)) {
                    $startMs = (float) $order->time_start;
                    $endMs = (float) $order->time_end;
                    $order_data[$key]->elapsed_minutes = ceil(max(0, ($endMs - $startMs) / 60000));
                }
                if (isset($product->product_time) && $product->product_time) {
                    $order_data[$key]->totalPrice = ! empty($order->time_end) ? $order->amount : 0;
                }
            }
        }

        return array_values($order_data);
    }

    public function takeaway_order_format($order)
    {
        $order_data = [];
        foreach ($order->order_details ?? $order as $key => $item) {
            $product = $item->product[0]->product;
            $product->notes = $item->product[0]->notes;
            $product->count = $item->product[0]->count;
            unset($product->addons);
            unset($product->variations);
            $variation = [];
            $addons = [];
            // $item->addons->addon->count = $item->addons->count;
            // $item->variations->variation->options = $item->variations->options;
            foreach ($item->variations as $key => $element) {
                $element->variation->options = $element->options;
                unset($element->options);
                $variation[] = $element->variation;
            }
            foreach ($item->addons as $key => $element) {
                $element->addon->count = $element->count;
                unset($element->count);
                $addons[] = $element->addon;
            }
            $order_data[$key] = $product;
            $order_data[$key]->cart_id = $order->id;
            $order_data[$key]->count = $item->product[0]->count;
            $order_data[$key]->excludes = $item->excludes;
            $order_data[$key]->extras = $item->extras;
            $order_data[$key]->variation_selected = $variation;
            $order_data[$key]->addons_selected = $addons;
        }

        return $order_data;
    }

    public function takeaway_kitchen_format($order)
    {
        $order_data = [];
        $kitchen_order = [];
        $kitchen_items = [];
        foreach ($order->order_details ?? $order as $key => $item) {
            $locale = 'ar';
            $product = collect([]);
            $product['id'] = $item->product[0]->product->id;
            $product['name'] = $item->product[0]->product->name;
            $product['category_id'] = $item->product[0]->product->category_id;
            $product['sub_category_id'] = $item->product[0]->product->sub_category_id;
            $product['notes'] = $item->product[0]->notes;
            $product['count'] = $item->product[0]->count;
            $product['weight'] = $item->product[0]->product->weight_status;

            // kitchen
            $kitchen = Kitchen::where(function ($q) use ($product) {
                $q->whereHas('products', function ($query) use ($product) {
                    $query->where('products.id', $product['id']);
                })
                    ->orWhereHas('category', function ($query) use ($product) {
                        $query->where('categories.id', $product['category_id'])
                            ->orWhere('categories.id', $product['sub_category_id']);
                    });
            })
                ->where('branch_id', auth()->user()->branch_id)
                ->with(['printer' => function ($query) use ($order) {
                    $query->whereHas('group_product', function ($q2) use ($order) {
                        $q2->where('group_products.id', $order->module_id);
                    })
                        ->orWhereJsonContains('module', $order->order_type);
                }])
                ->first();
            $printers = $kitchen?->printer ?? collect([]);
            if ($printers->count() > 0) {
                $kitchen->print_name = $printers[0]->print_name;
                $kitchen->print_ip = $printers[0]->print_ip;
                $kitchen->print_status = $printers[0]->print_status;
                $kitchen->print_type = $printers[0]->print_type;
                $kitchen->print_port = $printers[0]->print_port;
            }
            if (! empty($kitchen) && $kitchen->type == 'kitchen') {
                $locale = Setting::where('name', 'kitchen_lang')
                    ->first()?->setting ?? 'ar';
            } elseif (! empty($kitchen) && $kitchen->type == 'brista') {
                $locale = Setting::where('name', 'brista_lang')
                    ->first()?->setting ?? 'ar';
            }

            $product['name'] = TranslationTbl::where('locale', $locale)
                ->where('key', $product['name'])
                ->orderByDesc('created_at')
                ->first()
                ?->value ?? $product['name'];

            $variation = [];
            $addons = [];
            $excludes = [];
            $extras = [];
            // $item->addons->addon->count = $item->addons->count;
            // $item->variations->variation->options = $item->variations->options;
            foreach ($item->variations as $key => $element) {
                $options_items = $element->options;
                $options = [];
                foreach ($options_items as $value) {
                    $option_element = TranslationTbl::where('locale', $locale)
                        ->where('key', $value->name)
                        ->orderByDesc('created_at')
                        ->first()
                        ?->value ?? $value->name;
                    $options[] = ['id' => $value->id, 'name' => $option_element];
                }
                $variation_element = TranslationTbl::where('locale', $locale)
                    ->where('key', $element?->variation?->name)
                    ->orderByDesc('created_at')
                    ->first()
                    ?->value ?? $element?->variation?->name;
                $variation[] = [
                    'id' => $element?->variation?->id,
                    'name' => $variation_element,
                    'options' => $options,
                ];
            }
            foreach ($item->addons as $key => $element) {
                $element->addon->count = $element->count;
                unset($element->count);
                $addon_element = TranslationTbl::where('locale', $locale)
                    ->where('key', $element->addon->name)
                    ->orderByDesc('created_at')
                    ->first()
                    ?->value ?? $element->addon->name;
                $addons[] = [
                    'id' => $element->addon->id,
                    'name' => $addon_element,
                    'count' => $element->addon->count,
                ];
            }
            foreach ($item->excludes as $element) {
                $exclude_element = TranslationTbl::where('locale', $locale)
                    ->where('key', $element->name)
                    ->orderByDesc('created_at')
                    ->first()
                    ?->value ?? $element->name;
                $excludes[] = [
                    'id' => $element->id,
                    'name' => $exclude_element,
                ];
            }
            foreach ($item->extras as $element) {
                $extra_element = TranslationTbl::where('locale', $locale)
                    ->where('key', $element->name)
                    ->orderByDesc('created_at')
                    ->first()
                    ?->value ?? $element->name;
                $extras[] = [
                    'id' => $element->id,
                    'name' => $extra_element,
                ];
            }
            $order_data[$key] = $product;
            $order_data[$key]['cart_id'] = $order->id;
            $order_data[$key]['count'] = $item->product[0]->count;
            $order_data[$key]['excludes'] = $excludes;
            $order_data[$key]['extras'] = $extras;
            $order_data[$key]['variation_selected'] = $variation;
            $order_data[$key]['addons_selected'] = $addons;
            if (! empty($kitchen)) {
                $kitchens = Kitchen::where(function ($q) use ($product) {
                    $q->whereHas('products', function ($query) use ($product) {
                        $query->where('products.id', $product['id']);
                    })
                        ->orWhereHas('category', function ($query) use ($product) {
                            $query->where('categories.id', $product['category_id'])
                                ->orWhere('categories.id', $product['sub_category_id']);
                        });
                })
                    ->where('branch_id', auth()->user()->branch_id)
                    ->with(['printer' => function ($query) use ($order) {
                        $query->whereHas('group_product', function ($q2) use ($order) {
                            $q2->where('group_products.id', $order->module_id);
                        })
                            ->orWhereJsonContains('module', $order->order_type);
                    }])
                    ->get()
                    ->map(function ($item) {
                        $printers = $item->printer;
                        if ($printers->count() > 0) {
                            $item->print_name = $printers[0]->print_name;
                            $item->print_ip = $printers[0]->print_ip;
                            $item->print_status = $printers[0]->print_status;
                            $item->print_type = $printers[0]->print_type;
                            $item->print_port = $printers[0]->print_port;
                        }

                        return $item;
                    });
                foreach ($kitchens as $kitchen) {
                    $kitchen_items[$kitchen->id] = $kitchen;
                    $kitchen_order[$kitchen->id][] = $order_data[$key];
                }
            }
        }

        return [
            'order_data' => $order_data,
            'kitchen_items' => $kitchen_items,
            'kitchen_order' => $kitchen_order,
        ];
    }

    public function dine_in_print($order, $locale = 'ar', $key = 0)
    {
        $order_data = [];
        foreach ($order->cart ?? $order as $key => $item) {
            if (isset($item->product)) {
                $product = $item->product[0]->product;
                // Kitchen
                $kitchen = Kitchen::where(function ($q) use ($product) {
                    $q->whereHas('products', function ($query) use ($product) {
                        $query->where('products.id', $product->id);
                    })
                        ->orWhereHas('category', function ($query) use ($product) {
                            $query->where('categories.id', $product->category_id)
                                ->orWhere('categories.id', $product->sub_category_id);
                        });
                })
                    ->where('branch_id', auth()->user()->branch_id)
                    ->first();
                if (! empty($kitchen) && $kitchen->type == 'kitchen') {
                    $locale = Setting::where('name', 'kitchen_lang')
                        ->first()?->setting ?? 'ar';
                } elseif (! empty($kitchen) && $kitchen->type == 'brista') {
                    $locale = Setting::where('name', 'brista_lang')
                        ->first()?->setting ?? 'ar';
                }

                $product_name = TranslationTbl::where('locale', $locale)
                    ->where('key', $product->name)
                    ->orderByDesc('created_at')
                    ->first()
                    ?->value ?? $product->name;
                unset($product->addons);
                unset($product->variations);
                $variation = [];
                $addons = [];
                $excludes = [];
                $extras = [];
                foreach ($item->variations as $key => $element) {
                    $options_items = $element->options;
                    $options = [];
                    foreach ($options_items as $value) {
                        $option_element = TranslationTbl::where('locale', $locale)
                            ->where('key', $value->name)
                            ->orderByDesc('created_at')
                            ->first()
                            ?->value ?? $value->name;
                        $options[] = ['id' => $value->id, 'name' => $option_element];
                    }
                    $variation_element = TranslationTbl::where('locale', $locale)
                        ->where('key', $element?->variation?->name)
                        ->orderByDesc('created_at')
                        ->first()
                        ?->value ?? $element?->variation?->name;
                    $variation[] = [
                        'id' => $element?->variation?->id,
                        'name' => $variation_element,
                        'options' => $options,
                    ];
                }
                foreach ($item->addons as $key => $element) {
                    $element->addon->count = $element->count;
                    unset($element->count);
                    $addon_element = TranslationTbl::where('locale', $locale)
                        ->where('key', $element->addon->name)
                        ->orderByDesc('created_at')
                        ->first()
                        ?->value ?? $element->addon->name;
                    $addons[] = [
                        'id' => $element->addon->id,
                        'name' => $addon_element,
                        'count' => $element->addon->count,
                    ];
                }
                foreach ($item->excludes as $element) {
                    $exclude_element = TranslationTbl::where('locale', $locale)
                        ->where('key', $element->name)
                        ->orderByDesc('created_at')
                        ->first()
                        ?->value ?? $element->name;
                    $excludes[] = [
                        'id' => $element->id,
                        'name' => $exclude_element,
                    ];
                }
                foreach ($item->extras as $element) {
                    $extra_element = TranslationTbl::where('locale', $locale)
                        ->where('key', $element->name)
                        ->orderByDesc('created_at')
                        ->first()
                        ?->value ?? $element->name;
                    $extras[] = [
                        'id' => $element->id,
                        'name' => $extra_element,
                    ];
                }
                // $item->addons->addon->count = $item->addons->count;
                // $item->variations->variation->options = $item->variations->options;
                $order_data[$key]['id'] = $product->id;
                $order_data[$key]['name'] = $product_name;
                $order_data[$key]['weight'] = $product->weight_status;
                $order_data[$key]['category_id'] = $product->category_id;
                $order_data[$key]['sub_category_id'] = $product->sub_category_id;
                $order_data[$key]['notes'] = $item->product[0]->notes;
                $order_data[$key]['count'] = $item->product[0]->count;
                $order_data[$key]['cart_id'] = $order->id;
                $order_data[$key]['excludes'] = $excludes;
                $order_data[$key]['extras'] = $extras;
                $order_data[$key]['variation_selected'] = $variation;
                $order_data[$key]['addons_selected'] = $addons;
            }

            return array_values($order_data);
        }
    }

    public function kitechen_cart($item, $kitchen_order)
    {
        // foreach ($item as $element) {
        //     $kitchen_item = KitchenItem::create([
        //         "kitchen_order_id" => $kitchen_order->id,
        //         "product_id" => $element['id'],
        //        // 'note' => $element->note,
        //     ]);
        //     foreach ($element['extras'] as $value) {
        //         KItemExtra::create([
        //             "kitchen_item_id" => $kitchen_item->id,
        //             "extra_id" => $value['id'],
        //         ]);
        //     }
        //     foreach ($element['excludes'] as $value) {
        //         KItemExclude::create([
        //             "kitchen_item_id" => $kitchen_item->id,
        //             "exclude_id" => $value['id'],
        //         ]);
        //     }
        //     foreach ($element['addons_selected'] as $value) {
        //         KItemAddon::create([
        //             "kitchen_item_id" => $kitchen_item->id,
        //             "addon_id" => $value['id'],
        //         ]);
        //     }
        //     foreach ($element['variation_selected'] as $value) {
        //         $variation = KItemVriation::create([
        //             "kitchen_item_id" => $kitchen_item->id,
        //             "variation_id" => $value['id'],
        //         ]);
        //         foreach ($value['options'] as $value_item) {
        //             KItemOption::create([
        //                 "kitchen_variation_id" => $variation->id,
        //                 "option_id" => $value_item['id'],
        //             ]);
        //         }
        //     }
        // }
    }

    public function calculate_cart_totals($request, $user)
    {
        $branch_id = $request->branch_id ?? null;
        if (empty($branch_id) && ! empty($request->address_id)) {
            $address = Address::find($request->address_id);
            $branch_id = $address?->zone?->branch_id;
        }
        $module = $request->branch_id ? 'take_away' : 'delivery';
        $locale = $request->locale ?? $request->query('locale', app()->getLocale());

        $carts = ProductCart::with([
            'product.translations', 'product.tax.tax_module.module',
            'product.discount' => fn ($q) => $q->where(fn ($d) => $d->whereJsonContains('module', 'app')->orWhereJsonContains('module', 'all')),
            'product.product_pricing' => fn ($q) => $q->where('branch_id', $branch_id),
            'variations_cart.variation.translations', 'variations_cart.options_cart.option.translations',
            'variations_cart.options_cart.option.option_pricing' => fn ($q) => $q->where('branch_id', $branch_id),
            'addons_cart.addon.translations', 'addons_cart.addon.tax',
            'extras_cart.extra.translations', 'extras_cart.extra.pricing',
            'excludes_cart.exclude.translations',
        ])->where('user_id', $user->id)->get();

        if ($carts->isEmpty()) {
            return null;
        }

        $cart_total_price = 0;
        $cart_total_tax = 0;
        $cart_total_discount = 0;
        foreach ($carts as $key => $cart) {
            $product = $cart->product;
            if (! $product) {
                continue;
            }

            $product_price = $product->product_pricing->first()?->price ?? $product->price;
            $tax_module = $product->tax_module?->map(function ($taxItem) use ($module, $branch_id) {
                $isFound = $taxItem->module->where('module', $module)->whereIn('app_type', ['online', 'all'])->where('branch_id', $branch_id);
                if ($isFound->count() > 0) {
                    return $taxItem->tax;
                }
            })->filter()->first();
            $product->tax = ! empty($tax_module) ? $tax_module : $product->tax;
            $my_discount = $product->discount?->start_date <= date('Y-m-d') && $product->discount?->end_date >= date('Y-m-d') ? $product->discount : null;

            $product_tax_val = 0;
            $product_discount_val = 0;
            if ($product->taxes?->setting == 'included') {
                if (! empty($my_discount)) {
                    $discounted_price = ($my_discount->type == 'precentage') ? $product_price - $my_discount->amount * $product_price / 100 : $product_price - $my_discount->amount;
                } else {
                    $discounted_price = $product_price;
                }

                if (empty($product->tax)) {
                    $price_with_tax = $discounted_price;
                    $product_tax_val = 0;
                } else {
                    $price_with_tax = $discounted_price;
                    if ($product->tax->type == 'value') {
                        $product_tax_val = $product->tax->amount;
                    } else {
                        $price_before_tax = $price_with_tax / (1 + ($product->tax->amount / 100));
                        $product_tax_val = $price_with_tax - $price_before_tax;
                    }
                }
                $product_discount_val = $product_price - $discounted_price;
                $base_product_price = $price_with_tax;
            } else {
                if (! empty($my_discount)) {
                    $discounted_price = ($my_discount->type == 'precentage') ? $product_price - $my_discount->amount * $product_price / 100 : $product_price - $my_discount->amount;
                } else {
                    $discounted_price = $product_price;
                }
                if (! empty($product->tax)) {
                    $tax_amt = ($product->tax->type == 'precentage') ? $discounted_price + $product->tax->amount * $discounted_price / 100 : $discounted_price + $product->tax->amount;
                } else {
                    $tax_amt = $discounted_price;
                }
                $product_tax_val = $tax_amt - $discounted_price;
                $product_discount_val = $product_price - $discounted_price;
                $base_product_price = $product_price + $product_tax_val;
            }

            $options_total_price = 0;
            $options_total_tax = 0;
            $addon_total_tax = 0;
            $addon_total_discount = 0;
            $addon_total_price = 0;
            $extra_total_price = 0;
            $extra_total_tax = 0;

            foreach ($cart->addons_cart as $addon_cart) {
                $addon = $addon_cart->addon;
                if (! $addon) {
                    continue;
                }
                $addon_price = $addon->price;
                $addon_tax_val = 0;
                $addon_discount_val = 0;
                if ($addon->taxes?->setting == 'included') {
                    $addon_price_with_tax = $addon_price;
                    if (empty($addon->tax)) {
                        $addon_tax_val = 0;
                    } else {
                        if ($addon->tax->type == 'value') {
                            $addon_tax_val = $addon->tax->amount;
                        } else {
                            $addon_price_before_tax = $addon_price_with_tax / (1 + ($addon->tax->amount / 100));
                            $addon_tax_val = $addon_price_with_tax - $addon_price_before_tax;
                        }
                    }
                    $addon_price = $addon_price_with_tax;
                } else {
                    $tax_amt = empty($addon->tax) ? $addon_price : (($addon->tax->type == 'precentage') ? $addon_price + $addon->tax->amount * $addon_price / 100 : $addon_price + $addon->tax->amount);
                    $addon_tax_val = $tax_amt - $addon_price;
                    $addon_price = $tax_amt;
                }
                $addon_total_tax += ($addon_tax_val * $addon_cart->quantity);
                $addon_total_discount += ($addon_discount_val * $addon_cart->quantity);
                $addon_total_price += ($addon_price * $addon_cart->quantity);
            }

            foreach ($cart->variations_cart as $var_cart) {
                foreach ($var_cart->options_cart as $opt_cart) {
                    $opt = $opt_cart->option;
                    if ($opt) {
                        $opt_price = $opt->option_pricing->first()?->price ?? $opt->price;
                        $opt_tax_val = 0;
                        if ($product->taxes?->setting == 'included') {
                            if (! empty($product->tax)) {
                                if ($product->tax->type == 'precentage') {
                                    $opt_tax_val = $opt_price - ($opt_price / (1 + ($product->tax->amount / 100)));
                                } else {
                                    $opt_tax_val = 0;
                                }
                            }
                        } else {
                            if (! empty($product->tax)) {
                                if ($product->tax->type == 'precentage') {
                                    $opt_tax_val = $opt_price * $product->tax->amount / 100;
                                    $opt_price = $opt_price + $opt_tax_val;
                                }
                            }
                        }
                        $options_total_tax += ($opt_tax_val * $opt_cart->quantity);
                        $options_total_price += ($opt_price * $opt_cart->quantity);
                    }
                }
            }

            foreach ($cart->extras_cart as $extra_cart) {
                $extra = $extra_cart->extra;
                if (! $extra) {
                    continue;
                }
                $extra_price = $extra->pricing->first()?->price ?? $extra->price;
                $extra_tax_val = 0;
                if ($product->taxes?->setting == 'included') {
                    if (! empty($product->tax)) {
                        if ($product->tax->type == 'precentage') {
                            $extra_tax_val = $extra_price - ($extra_price / (1 + ($product->tax->amount / 100)));
                        } else {
                            $extra_tax_val = 0;
                        }
                    }
                    $extra_price_after_tax = $extra_price;
                } else {
                    if (! empty($product->tax)) {
                        if ($product->tax->type == 'precentage') {
                            $extra_tax_val = $extra_price * $product->tax->amount / 100;
                            $extra_price_after_tax = $extra_price + $extra_tax_val;
                        } else {
                            $extra_tax_val = 0;
                            $extra_price_after_tax = $extra_price;
                        }
                    } else {
                        $extra_price_after_tax = $extra_price;
                    }
                }
                $extra_total_price += ($extra_price_after_tax * $extra_cart->quantity);
                $extra_total_tax += ($extra_tax_val * $extra_cart->quantity);
            }

            $product_total = $cart->quantity * ($options_total_price + $base_product_price + $addon_total_price + $extra_total_price);
            $cart_total_price += $product_total;
            $cart_total_tax += $cart->quantity * ($product_tax_val + $options_total_tax + $addon_total_tax + $extra_total_tax);
            $cart_total_discount += $cart->quantity * ($product_discount_val + $addon_total_discount);
        }

        $coupon_discount = $request->coupon_discount ?? 0;
        $delivery_fees = $request->delivery_fees ?? 0;
        $service_fees = $request->service_fees ?? 0;

        return [
            'total_price' => $cart_total_price,
            'total_tax' => $cart_total_tax,
            'total_discount' => $cart_total_discount,
            'amount' => $cart_total_price + $delivery_fees + $service_fees - $coupon_discount,
            'carts' => $carts,
        ];
    }

    public function make_order_from_cart($request, $paymob = 0)
    {
        $user = auth()->user();
        $cart_data = $this->calculate_cart_totals($request, $user);

        if (! $cart_data) {
            return ['errors' => 'Cart is empty'];
        }

        $branch_id = $request->branch_id ?? null;
        if (empty($branch_id) && ! empty($request->address_id)) {
            $address = Address::find($request->address_id);
            $branch_id = $address?->zone?->branch_id;
        }
        $locale = $request->locale ?? $request->query('locale', app()->getLocale());

        $branch_off = BranchOff::where('branch_id', $branch_id)->get();
        $products_off = $branch_off->pluck('product_id')->filter()->values()->all();
        $options_off = $branch_off->pluck('option_id')->filter()->values()->all();
        $categories_off = $branch_off->pluck('category_id')->filter()->values()->all();

        $orderRequest = $request->only($this->paymentRequest);
        $orderRequest['branch_id'] = $branch_id;
        if (! $request->user_id || $request->user_id != 'empty') {
            $orderRequest['user_id'] = $user->id;
        }
        if (! empty($request->customer_id) && is_numeric($request->customer_id)) {
            $orderRequest['customer_id'] = $request->customer_id;
        }

        $orderRequest['order_status'] = 'pending';
        if ($request->table_id) {
            $orderRequest['table_id'] = $request->table_id;
        }
        if ($request->captain_id) {
            $orderRequest['captain_id'] = $request->captain_id;
        }
        if ($request->cashier_id) {
            $orderRequest['cashier_id'] = $request->cashier_id;
        }
        if ($request->cashier_man_id) {
            $orderRequest['cashier_man_id'] = $request->cashier_man_id;
        }
        if ($request->shift) {
            $orderRequest['shift'] = $request->shift;
        }

        $points = 0;
        $items = [];
        $order_details = [];

        foreach ($cart_data['carts'] as $key => $cart) {
            $product = $cart->product;
            if (in_array($product->id, $products_off) || in_array($product->category_id, $categories_off) || in_array($product->sub_category_id, $categories_off)) {
                return ['errors' => 'Product '.$product->name.' is not found at this branch'];
            }
            $points += $product->points * $cart->quantity;
            $items[] = ['name' => $product->name, 'amount_cents' => (int) round($product->price * 100), 'description' => $product->description, 'quantity' => $cart->quantity];

            $order_details[$key]['extras'] = [];
            $order_details[$key]['addons'] = [];
            $order_details[$key]['excludes'] = [];
            $order_details[$key]['product'] = [];
            $order_details[$key]['variations'] = [];

            $product_resource = ProductResource::collection(collect([$product]))[0] ?? null;
            $order_details[$key]['product'][] = ['product' => $product_resource, 'count' => $cart->quantity, 'notes' => $cart->note];

            foreach ($cart->addons_cart as $addon_cart) {
                $addon = $addon_cart->addon;
                $addon_resource = AddonResource::collection(collect([$addon]))[0] ?? null;
                $order_details[$key]['addons'][] = ['addon' => $addon_resource, 'count' => $addon_cart->quantity];
            }
            foreach ($cart->extras_cart as $extra_cart) {
                $extra = $extra_cart->extra;
                if ($extra) {
                    $extra_resource = ExtraResource::collection(collect([$extra]))[0] ?? null;
                    $order_details[$key]['extras'][] = $extra_resource;
                }
            }
            foreach ($cart->excludes_cart as $exclude_cart) {
                $exclude = $exclude_cart->exclude;
                if ($exclude) {
                    $exclude_resource = ExcludeResource::collection(collect([$exclude]))[0] ?? null;
                    $order_details[$key]['excludes'][] = $exclude_resource;
                }
            }
            foreach ($cart->variations_cart as $var_cart) {
                foreach ($var_cart->options_cart as $opt_cart) {
                    $opt = $opt_cart->option;
                    if ($opt) {
                        if (in_array($opt->id, $options_off)) {
                            return ['errors' => 'Option '.$opt->name.' at product '.$product->name.' is not found at this branch'];
                        }
                        $points += $opt->points * $cart->quantity;
                    }
                }
                $var_resource = VariationResource::collection(collect([$var_cart->variation]))[0] ?? null;
                $opts_resource = OptionResource::collection($var_cart->options_cart->pluck('option'))->toArray(request());
                $order_details[$key]['variations'][] = ['variation' => $var_resource, 'options' => $opts_resource];
            }
        }

        $orderRequest['amount'] = $cart_data['amount'];
        $orderRequest['total_tax'] = $cart_data['total_tax'];
        $orderRequest['total_discount'] = $cart_data['total_discount'];
        $orderRequest['points'] = $points;
        $orderRequest['coupon_discount'] = $request->coupon_discount ?? 0;

        $order = $this->order->create($orderRequest);
        if (! empty($user)) {
            $user->save();
        }

        foreach ($cart_data['carts'] as $key => $cart) {
            $this->order_details->create(['order_id' => $order->id, 'product_id' => $cart->product_id, 'count' => $cart->quantity, 'product_index' => $key]);
            foreach ($cart->addons_cart as $addon_cart) {
                $this->order_details->create(['order_id' => $order->id, 'product_id' => $cart->product_id, 'addon_id' => $addon_cart->addon_id, 'count' => $cart->quantity, 'addon_count' => $addon_cart->quantity, 'product_index' => $key]);
            }
            foreach ($cart->extras_cart as $extra_cart) {
                $this->order_details->create(['order_id' => $order->id, 'product_id' => $cart->product_id, 'extra_id' => $extra_cart->extra_id, 'count' => $cart->quantity, 'product_index' => $key]);
            }
            foreach ($cart->excludes_cart as $exclude_cart) {
                $this->order_details->create(['order_id' => $order->id, 'product_id' => $cart->product_id, 'exclude_id' => $exclude_cart->exclude_id, 'count' => $cart->quantity, 'product_index' => $key]);
            }
            foreach ($cart->variations_cart as $var_cart) {
                foreach ($var_cart->options_cart as $opt_cart) {
                    $this->order_details->create(['order_id' => $order->id, 'product_id' => $cart->product_id, 'variation_id' => $var_cart->variation_id, 'option_id' => $opt_cart->option_id, 'count' => $cart->quantity, 'product_index' => $key]);
                }
            }
        }

        $order->order_details = json_encode($order_details);
        $order->load('payment_method.geidea');
        $gedia_status = false;
        $gedia = null;
        if ($paymob) {
            $order->status = 2;
            $order->save();
        }
        if (! empty($order->payment_method?->geidea)) {
            try {
                $order->status = 2;
                $order->save();
                $gedia = $this->geidea($order->id, $order->amount);
                if (isset($gedia['error'])) {
                    \Log::error('Geidea error: '.$gedia['error']);
                    $gedia = null;
                    $gedia_status = false;
                } else {
                    $gedia_status = isset($gedia['session_id']);
                }
            } catch (\Exception $e) {
                \Log::error('Geidea exception: '.$e->getMessage());
                $gedia = null;
                $gedia_status = false;
            }
        }
        $order->save();

        return [
            'payment' => $order,
            'orderItems' => $order_details,
            'items' => $items,
            'gedia' => $gedia,
            'gedia_status' => $gedia_status,
        ];
    }
}
