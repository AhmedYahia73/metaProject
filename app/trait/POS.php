<?php

namespace App\trait;

use App\Http\Resources\AddonResource;
use App\Http\Resources\ExcludeResource;
use App\Http\Resources\ExtraResource;
use App\Http\Resources\OptionResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\VariationResource;
use App\Models\BranchOff;
use App\Models\Bundle;
use App\Models\Cashier;
use App\Models\FinantiolAcounting;
use App\Models\Order;
use App\Models\OrderBundle;
use App\Models\OrderBundleProduct;
use App\Models\OrderOptionBundle;
use App\Models\OrderVariationBundle;
use App\Models\Payment;
use App\Models\TimeSittings;
use Carbon\Carbon;

trait POS
{
    // This Traite About Place Order
    protected $orderDataRequest = [
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
        'cashier_id',
        'cashier_man_id',
        'shift',
        'pos',
        'status',
        'cash_with_delivery',
        'balance',
        'user_id',
        'due',
        'dicount_id',
        'module_id',
        'captain_id',
        'due_module',
        'module_order_number',
        'service_fees',
        'service_fees_id',
        'delivery_fees',
        'prepare_order',
    ];

    public function take_away_make_order($request, $paymob = 0)
    {
        $branch_off = BranchOff::where('branch_id', $request->branch_id)
            ->get();
        $products_off = $branch_off->pluck('product_id')->filter()->values()->all();
        $options_off = $branch_off->pluck('option_id')->filter()->values()->all();
        $categories_off = $branch_off->pluck('category_id')->filter()->values()->all();
        $orderRequest = $request->only($this->orderDataRequest);
        $user = auth()->user();

        if (! empty($request->customer_id) && is_numeric($request->customer_id)) {
            $orderRequest['customer_id'] = $request->customer_id;
        }

        $orderRequest['order_status'] = 'pending';
        if ($request->table_id) {
            $orderRequest['table_id'] = $request->table_id;
        }
        if ($request->order_pending) {
            $orderRequest['order_active'] = 0;
        }

        $locale = $request->locale ?? $request->query('locale', app()->getLocale()); // Get Local Translation
        // $points = 0;
        $items = [];
        $order_details = [];
        $bundle_arr = [];
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
                    // $points += $item->points * $product['count'];
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
                                    // $points += $option_points->points * $product['count'];
                                }
                            }
                        }
                    }
                }
            }
        }
        // $orderRequest['points'] = $points;
        $orderRequest['order_number'] = $this->generate_order_number();
        $order = $this->order
            ->create($orderRequest);
        $order->order_number = $orderRequest['order_number'];
        $order->save();
        // payment using financial
        if (isset($request->financials)) {
            foreach ($request->financials as $element) {
                $this->financial
                    ->create([
                        'order_id' => $order->id,
                        'financial_id' => $element['id'],
                        'cashier_id' => $request->cashier_id,
                        'cashier_man_id' => $request->cashier_man_id,
                        'amount' => $element['amount'],
                        'description' => isset($element['description']) ? $element['description'] : null,
                        'transition_id' => isset($element['transition_id']) ? $element['transition_id'] : null,
                    ]);
                $financial = FinantiolAcounting::where('id', $element['id'])
                    ->first();
                if ($financial) {
                    $financial->balance += $element['amount'];
                    $financial->save();
                }
            }
        }
        $order_details = $this->order_details($request, $order, $locale);
        $order->order_details = json_encode($order_details['order_details']);
        $order->save();
        if (isset($request->bundles)) {
            foreach ($request->bundles as $bundle) {
                $order_bundle_id = OrderBundle::create([
                    'bundle_id' => $bundle['id'],
                    'count' => $bundle['count'],
                    'order_id' => $order->id,
                ]);
                foreach ($bundle['products'] as $product) {
                    $product_item = OrderBundleProduct::create([
                        'order_bundle_id' => $order_bundle_id->id,
                        'product_id' => $product['id'],
                    ]);
                    foreach ($product['variation'] as $var_element) {
                        OrderVariationBundle::create([
                            'order_bundle_id' => $order_bundle_id->id,
                            'variation_id' => $var_element['id'],
                            'order_bundle_p_id' => $product_item->id,
                        ]);
                        foreach ($var_element['options'] as $option) {
                            OrderOptionBundle::create([
                                'order_bundle_id' => $order_bundle_id->id,
                                'variation_id' => $var_element['id'],
                                'option_id' => $option,
                                'order_bundle_p_id' => $product_item->id,
                            ]);
                        }
                    }
                }
            }
        }

        return [
            'order' => $order,
        ];
    }

    public function order_details($request, $order, $locale)
    {
        $module = $order->order_type;
        $order_details = [];
        $branch_id = 0;
        $today = now()->format('Y-m-d');
        if ($request->cashier_id) {
            $branch_id = Cashier::where('id', $request->cashier_id)
                ->first()
                ?->branch_id ?? 0;
        }
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $key => $product) {
                $amount_product = 0;
                $order_details[$key]['extras'] = [];
                $order_details[$key]['addons'] = [];
                $order_details[$key]['excludes'] = [];
                $order_details[$key]['product'] = [];
                $order_details[$key]['variations'] = [];

                $this->order_details
                    ->create([
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'count' => $product['count'],
                        'product_index' => $key,
                    ]);
                $product_item = $this->products
                    ->where('id', $product['product_id'])
                    ->with([
                        'addons' => fn ($q) => $q->withLocale($locale),
                        'sub_category_addons' => fn ($q) => $q->withLocale($locale),
                        'category_addons' => fn ($q) => $q->withLocale($locale),
                        'excludes' => fn ($q) => $q->withLocale($locale),
                        'extra', 'sales_count', 'tax', 'product_pricing', 'pos_pricing',
                        'variations' => fn ($q) => $q->withLocale($locale)->with([
                            'options' => fn ($qo) => $qo->where('status', 1)->withLocale($locale)->with([
                                'extra' => fn ($qe) => $qe->with('parent_extra')->withLocale($locale),
                                'option_pricing',
                            ]),
                        ]),
                        'tax_module' => fn ($q) => $q->whereHas('module', fn ($qm) => $qm->where('branch_id', $branch_id)->whereIn('app_type', ['pos', 'all']))
                            ->with(['tax', 'module']),
                        'discount' => fn ($q) => $q->where(fn ($d) => $d->whereJsonContains('module', 'pos')->orWhereJsonContains('module', 'all')),
                    ])
                    ->withLocale($locale)
                    ->first();
                // Resolve Pricing
                $new_price = $product_item->product_pricing->firstWhere('branch_id', $branch_id)?->price;
                if (empty($new_price)) {
                    $new_price = $product_item->pos_pricing->firstWhere('module', $module)?->price ?? $product_item->price;
                }
                $product_item->price = $new_price;
                $product_item->favourite = false;

                // Stock Calculation (Using in-memory collections instead of queries)
                if ($product_item->stock_type == 'fixed') {
                    $product_item->count = $product_item->sales_count->sum('count');
                    $product_item->in_stock = $product_item->number > $product_item->count;
                } elseif ($product_item->stock_type == 'daily') {
                    $product_item->count = $product_item->sales_count->where('date', $today)->sum('count');
                    $product_item->in_stock = $product_item->number > $product_item->count;
                }

                // Resolve Tax Module efficiently
                $resolved_tax = $product_item->tax_module->first()?->tax ?? $product_item->tax ?? null;

                // Map Variations & Options
                $product_item->variations = $product_item->variations->map(function ($variation) use ($branch_id, $resolved_tax) {
                    $variation->options = $variation->options
                        ->map(function ($element) use ($branch_id, $resolved_tax) {
                            $element->price = $element->option_pricing->firstWhere('branch_id', $branch_id)?->price ?? $element->price;
                            $element->new_tax = $resolved_tax;

                            return $element;
                        });

                    return $variation;
                });

                // Map Addons
                $product_item->addons = $product_item->addons->map(function ($addon) use ($product_item) {
                    $addon->discount = $product_item->discount;

                    return $addon;
                });

                if (! empty($resolved_tax)) {
                    $product_item->tax = $resolved_tax;
                }
                $product_item = collect([$product_item]);
                $product_item = ProductResource::collection($product_item);
                $product_item = count($product_item) > 0 ? $product_item[0] : null;
                $order_details[$key]['product'][] = [
                    'product' => $product_item,
                    'count' => $product['count'],
                    'notes' => isset($product['note']) ? $product['note'] : null,
                ];
                // Add product price
                $amount_product += $product_item->price;
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
                        $amount_product += $this->options
                            ->whereIn('id', $variation['option_id'])
                            ->sum('price');
                    }
                }
            }
        }

        if (isset($request->bundles)) {
            foreach ($request->bundles as $bundle_item) {
                $bundle = Bundle::where('id', $bundle_item)
                    ->with('products')
                    ->first();
                $bundle_arr[] = [
                    'id' => $bundle->id,
                    'name' => $bundle->name,
                    'price' => $bundle->price,
                    'discount' => $bundle?->discount?->name,
                    'tax' => $bundle?->tax?->name,
                    'price' => $bundle->price,
                    'products' => $bundle?->products
                        ?->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'name' => $item->name,
                            ];
                        }),
                ];
            }
        }

        return ['order_details' => $order_details];
    }

    public function dine_in_split_payment($request, $paymob = 0)
    {
        $orderRequest = $request->only($this->orderDataRequest);
        $user = auth()->user();

        // if (!empty($request->customer_id) && is_numeric($request->customer_id)) {
        //     $orderRequest['customer_id'] = $request->customer_id;
        // }

        $orderRequest['order_status'] = 'pending';
        // if ($request->table_id) {
        //     $orderRequest['table_id'] = $request->table_id;
        // }

        $locale = $request->locale ?? $request->query('locale', app()->getLocale()); // Get Local Translation
        // $points = 0;
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
                    // $points += $item->points * $product['count'];
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
                                    // $points += $option_points->points * $product['count'];
                                }
                            }
                        }
                    }
                }
            }
        }
        // $orderRequest['points'] = $points;
        $orderRequest['order_number'] = $this->generate_order_number();
        $order = $this->order
            ->create($orderRequest);
        $order->order_number = $orderRequest['order_number'];
        $order->save();
        // payment using financial
        if ($request->financials && is_array($request->financials)) {
            foreach ($request->financials as $element) {
                $this->financial
                    ->create([
                        'order_id' => $order->id,
                        'financial_id' => $element['id'],
                        'cashier_id' => $request->cashier_id,
                        'cashier_man_id' => $request->cashier_man_id,
                        'amount' => $element['amount'],
                        'description' => isset($element['description']) ? $element['description'] : null,
                        'transition_id' => isset($element['transition_id']) ? $element['transition_id'] : null,
                    ]);
                $financial = FinantiolAcounting::where('id', $element['id'])
                    ->first();
                if ($financial) {
                    $financial->balance += $element['amount'];
                    $financial->save();
                }
            }
        }
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $key => $product) {
                $amount_product = 0;
                $order_details[$key]['extras'] = [];
                $order_details[$key]['addons'] = [];
                $order_details[$key]['excludes'] = [];
                $order_details[$key]['product'] = [];
                $order_details[$key]['variations'] = [];

                $this->order_details
                    ->create([
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'count' => $product['count'],
                        'product_index' => $key,
                    ]);
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
                    'notes' => isset($product['note']) ? $product['note'] : null,
                ];
                // Add product price
                $amount_product += $product_item->price;
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
                        $amount_product += $this->options
                            ->whereIn('id', $variation['option_id'])
                            ->sum('price');
                    }
                }
            }
        }
        if (isset($request->bundles)) {
            foreach ($request->bundles as $bundle) {
                $order_bundle_id = OrderBundle::create([
                    'bundle_id' => $bundle['id'],
                    'count' => $bundle['count'],
                    'order_id' => $order->id,
                ]);
                foreach ($bundle['variation'] as $var_element) {
                    OrderVariationBundle::create([
                        'order_bundle_id' => $order_bundle_id->id,
                        'variation_id' => $var_element['id'],
                    ]);
                    foreach ($var_element['options'] as $option) {
                        OrderOptionBundle::create([
                            'order_bundle_id' => $order_bundle_id->id,
                            'variation_id' => $var_element['id'],
                            'option_id' => $option,
                        ]);
                    }
                }
            }
        }
        $order->order_details = json_encode($order_details);
        $order->save();

        return [
            'order' => $order,
        ];
    }

    public function delivery_make_order($request, $paymob = 0)
    {
        $branch_off = BranchOff::where('branch_id', $request->branch_id)
            ->get();
        $products_off = $branch_off->pluck('product_id')->filter()->values()->all();
        $options_off = $branch_off->pluck('option_id')->filter()->values()->all();
        $categories_off = $branch_off->pluck('category_id')->filter()->values()->all();
        $orderRequest = $request->only($this->orderDataRequest);
        $user = auth()->user();

        // if (!empty($request->customer_id) && is_numeric($request->customer_id)) {
        //     $orderRequest['customer_id'] = $request->customer_id;
        // }

        $orderRequest['order_status'] = 'pending';
        // if ($request->table_id) {
        //     $orderRequest['table_id'] = $request->table_id;
        // }
        if ($request->order_pending) {
            $orderRequest['order_active'] = 0;
        }

        $locale = $request->locale ?? $request->query('locale', app()->getLocale()); // Get Local Translation
        // $points = 0;
        $items = [];
        $order_details = [];
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $product) {
                $item = $this->products
                    ->where('id', $product['product_id'] ?? null)
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
                        'quantity' => $product['count'] ?? 0,
                    ];
                    // $points += $item->points * $product['count'];
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
                                    // $points += $option_points->points * $product['count'];
                                }
                            }
                        }
                    }
                }
            }
        }
        // $orderRequest['points'] = $points;
        $orderRequest['order_number'] = $this->generate_order_number();
        $order = $this->order
            ->create($orderRequest);
        $order->order_number = $orderRequest['order_number'];
        $order->save();
        // payment using financial
        if ($request->financials && is_array($request->financials)) {
            foreach ($request->financials as $element) {
                $this->financial
                    ->create([
                        'order_id' => $order->id,
                        'financial_id' => $element['id'] ?? null,
                        'cashier_id' => $request->cashier_id,
                        'cashier_man_id' => $request->cashier_man_id,
                        'amount' => $element['amount'] ?? null,
                        'description' => isset($element['description']) ? $element['description'] : null,
                        'transition_id' => isset($element['transition_id']) ? $element['transition_id'] : null,
                    ]);
                $financial = FinantiolAcounting::where('id', $element['id'])
                    ->first();
                if ($financial) {
                    $financial->balance += $element['amount'];
                    $financial->save();
                }
            }
        }

        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $key => $product) {
                $amount_product = 0;
                $order_details[$key]['extras'] = [];
                $order_details[$key]['addons'] = [];
                $order_details[$key]['excludes'] = [];
                $order_details[$key]['product'] = [];
                $order_details[$key]['variations'] = [];

                if (isset($request->order_pending) && ! $request->order_pending) {
                    $this->order_details
                        ->create([
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'count' => $product['count'],
                            'product_index' => $key,
                        ]);
                }
                $product_item = $this->products
                    ->where('id', $product['product_id'] ?? null)
                    ->withLocale($locale)
                    ->first();
                $product_item = collect([$product_item]);
                $product_item = ProductResource::collection($product_item);
                $product_item = count($product_item) > 0 ? $product_item[0] : null;
                $order_details[$key]['product'][] = [
                    'product' => $product_item,
                    'count' => $product['count'] ?? null,
                    'notes' => isset($product['note']) ? $product['note'] : null,
                ];
                // Add product price
                $amount_product += $product_item->price ?? 0;
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
                        $amount_product += $this->options
                            ->whereIn('id', $variation['option_id'])
                            ->sum('price');
                    }
                }
            }
        }

        if (isset($request->bundles)) {
            foreach ($request->bundles as $bundle) {
                $order_bundle_id = OrderBundle::create([
                    'bundle_id' => $bundle['id'],
                    'count' => $bundle['count'],
                    'order_id' => $order->id,
                ]);
                foreach ($bundle['products'] as $product) {
                    $product_item = OrderBundleProduct::create([
                        'order_bundle_id' => $order_bundle_id->id,
                        'product_id' => $product['id'],
                    ]);
                    foreach ($product['variation'] as $var_element) {
                        OrderVariationBundle::create([
                            'order_bundle_id' => $order_bundle_id->id,
                            'variation_id' => $var_element['id'],
                            'order_bundle_p_id' => $product_item->id,
                        ]);
                        foreach ($var_element['options'] as $option) {
                            OrderOptionBundle::create([
                                'order_bundle_id' => $order_bundle_id->id,
                                'variation_id' => $var_element['id'],
                                'option_id' => $option,
                                'order_bundle_p_id' => $product_item->id,
                            ]);
                        }
                    }
                }
            }
        }

        $order->order_details = json_encode($order_details);
        $order->save();
        $order->load(['user:id,f_name,l_name,phone', 'address.zone:id,zone,price']);

        return [
            'order' => $order,
        ];
    }

    public function dine_in_make_order($request, $paymob = 0)
    {
        $branch_off = BranchOff::where('branch_id', $request->branch_id)
            ->get();
        $products_off = $branch_off->pluck('product_id')->filter()->values()->all();
        $options_off = $branch_off->pluck('option_id')->filter()->values()->all();
        $categories_off = $branch_off->pluck('category_id')->filter()->values()->all();
        $orderRequest = $request->only($this->orderDataRequest);
        $user = auth()->user();

        if (! empty($request->customer_id) && is_numeric($request->customer_id)) {
            $orderRequest['customer_id'] = $request->customer_id;
        }

        $orderRequest['order_status'] = 'pending';
        if ($request->table_id) {
            $orderRequest['table_id'] = $request->table_id;
        }

        $locale = $request->locale ?? $request->query('locale', app()->getLocale()); // Get Local Translation
        // $points = 0;
        $items = [];
        $order_details = [];
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $product) {
                $item = $this->products
                    ->where('id', $product['product_id'])
                    ->first();
                if (! empty($item)) {
                    if (in_array($item->id, $products_off) ||
                    in_array($item->category_id, $categories_off) ||
                    in_array($item->sub_category_id, $categories_off)) {
                        return [
                            'errors' => 'Product '.$item->name.
                            ' is not found at this branch you can change branch or order',
                        ];
                    }
                    $items[] = ['name' => $item->name,
                        'amount_cents' => $item->price,
                        'description' => $item->description,
                        'quantity' => $product['count'],
                    ];
                    // $points += $item->points * $product['count'];
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
                                    // $points += $option_points->points * $product['count'];
                                }
                            }
                        }
                    }
                }
            }
        }
        // $orderRequest['points'] = $points;
        $orderRequest['order_number'] = $this->generate_order_number();
        $order = $this->order
            ->create($orderRequest);
        $order->order_number = $orderRequest['order_number'];
        $order->save();
        // payment using financial
        foreach ($request->financials as $element) {
            $this->financial
                ->create([
                    'order_id' => $order->id,
                    'financial_id' => $element['id'],
                    'cashier_id' => $request->cashier_id,
                    'cashier_man_id' => $request->cashier_man_id,
                    'amount' => $element['amount'],
                    'description' => isset($element['description']) ? $element['description'] : null,
                    'transition_id' => isset($element['transition_id']) ? $element['transition_id'] : null,
                ]);

            $financial = FinantiolAcounting::where('id', $element['id'])
                ->first();
            if ($financial) {
                $financial->balance += $element['amount'];
                $financial->save();
            }
        }
        if (isset($request->products)) {
            $request->products = is_string($request->products) ? json_decode($request->products) : $request->products;
            foreach ($request->products as $key => $product) {
                $amount_product = 0;
                $order_details[$key]['extras'] = [];
                $order_details[$key]['addons'] = [];
                $order_details[$key]['excludes'] = [];
                $order_details[$key]['product'] = [];
                $order_details[$key]['variations'] = [];

                $this->order_details
                    ->create([
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'count' => $product['count'],
                        'product_index' => $key,
                    ]);
                $product_item = $this->products
                    ->where('id', $product['product_id'])
                    ->withLocale($locale)
                    ->first();
                if ($product_item) {
                    $product_item = collect([$product_item]);
                    $product_item = ProductResource::collection($product_item);
                    $product_item = count($product_item) > 0 ? $product_item[0] : null;
                    $order_details[$key]['product'][] = [
                        'product' => $product_item,
                        'count' => $product['count'],
                        'notes' => isset($product['note']) ? $product['note'] : null,
                    ];
                    // Add product price
                    $amount_product += $product_item->price;
                }
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

                        $addon_item = $this->addons
                            ->where('id', $addon['addon_id'])
                            ->withLocale($locale)
                            ->first();
                        if ($addon_item) {
                            $addon_item = collect([$addon_item]);
                            $addon_item = AddonResource::collection($addon_item);
                            $addon_item = count($addon_item) > 0 ? $addon_item[0] : null;
                            $order_details[$key]['addons'][] = [
                                'addon' => $addon_item,
                                'count' => $addon['count'],
                            ];
                        }
                    }
                }
                if (isset($product['extra_id'])) {
                    foreach ($product['extra_id'] as $extra) {
                        $extra_item = $this->extras
                            ->where('id', $extra)
                            ->withLocale($locale)
                            ->first();
                        if ($extra_item) {
                            $extra_item = collect([$extra_item]);
                            $extra_item = ExtraResource::collection($extra_item);
                            $extra_item = count($extra_item) > 0 ? $extra_item[0] : null;
                            $order_details[$key]['extras'][] = $extra_item;
                        }
                    }
                }
                if (isset($product['product_extra_id'])) {
                    foreach ($product['product_extra_id'] as $extra) {
                        $extra_item = $this->extras
                            ->where('id', $extra)
                            ->withLocale($locale)
                            ->first();
                        if ($extra_item) {
                            $extra_item = collect([$extra_item]);
                            $extra_item = ExtraResource::collection($extra_item);
                            $extra_item = count($extra_item) > 0 ? $extra_item[0] : null;
                            $order_details[$key]['extras'][] = $extra_item;
                        }
                    }
                }
                if (isset($product['variation'])) {
                    foreach ($product['variation'] as $variation) {
                        $variation_items = $this->variation
                            ->where('id', $variation['variation_id'])
                            ->withLocale($locale)
                            ->first();
                        $variations = collect([$variation_items]);
                        $options = $this->options
                            ->whereIn('id', $variation['option_id'])
                            ->withLocale($locale)
                            ->get();
                        if ($variation_items && count($options) > 0) {
                            $variations = VariationResource::collection($variations);
                            $variations = count($variations) > 0 ? $variations[0] : null;

                            $options = OptionResource::collection($options);
                            $order_details[$key]['variations'][] = [
                                'variation' => $variations,
                                'options' => $options,
                            ];
                            $amount_product += $this->options
                                ->whereIn('id', $variation['option_id'])
                                ->sum('price');
                        }
                    }
                }
            }

            if (isset($request->bundles)) {
                foreach ($request->bundles as $bundle) {
                    $order_bundle_id = OrderBundle::create([
                        'bundle_id' => $bundle['id'],
                        'count' => $bundle['count'],
                        'order_id' => $order->id,
                    ]);
                    foreach ($bundle['variation'] as $var_element) {
                        OrderVariationBundle::create([
                            'order_bundle_id' => $order_bundle_id->id,
                            'variation_id' => $var_element['id'],
                        ]);
                        foreach ($var_element['options'] as $option) {
                            OrderOptionBundle::create([
                                'order_bundle_id' => $order_bundle_id->id,
                                'variation_id' => $var_element['id'],
                                'option_id' => $option,
                            ]);
                        }
                    }
                }
            }
            $order->order_details = json_encode($order_details);
            $order->save();
        }

        return [
            'payment' => $order,
        ];
    }

    public function generate_order_number()
    {
        $now = Carbon::now();
        $time_sittings = TimeSittings::get();

        if ($time_sittings->count() == 0) {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
            if ($now->hour < 4) {
                $start->subDay();
                $end->subDay();
            }
        } else {
            $min_from = null;
            $max_end = null;

            foreach ($time_sittings as $sitting) {
                $sitting_from = Carbon::parse($sitting->from);
                $sitting_end = $sitting_from->copy()->addHours($sitting->hours)->addMinutes($sitting->minutes);

                if (is_null($min_from) || $sitting_from->format('H:i:s') < $min_from->format('H:i:s')) {
                    $min_from = $sitting_from;
                }
                if (is_null($max_end) || $sitting_end->format('H:i:s') > $max_end->format('H:i:s')) {
                    $max_end = $sitting_end;
                }
            }

            $business_date = $now->copy();
            if ($business_date->hour < 4) {
                $business_date->subDay();
            }

            $start = Carbon::parse($business_date->format('Y-m-d').' '.$min_from->format('H:i:s'));
            $end = Carbon::parse($business_date->format('Y-m-d').' '.$max_end->format('H:i:s'));

            if ($end <= $start || $max_end->format('H:i:s') < $min_from->format('H:i:s')) {
                $end->addDay();
            }
        }

        $max_order_number = Order::whereBetween('created_at', [$start, $end])
            ->where('order_number', '<', 1000000000)
            ->max('order_number');

        if ($max_order_number) {
            return $max_order_number + 1;
        } else {
            $cashier_id = auth()->check() ? auth()->user()->id : 0;

            return (int) ($cashier_id.'1');
        }
    }
}
