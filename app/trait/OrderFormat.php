<?php

namespace App\trait;

use App\Models\GroupAddonPrice;
use App\Models\GroupExtraPrice;
use App\Models\GroupOptionPrice;
use App\Models\GroupPrice;
use App\Models\GroupProduct;
use App\Models\Order;
use App\Models\TranslationTbl;

trait OrderFormat
{
    /**
     * Batch load all translations for order details in a single query
     * instead of querying one by one in loops
     */
    private function batchTranslations(array $keys, string $locale): array
    {
        if (empty($keys)) {
            return [];
        }

        return TranslationTbl::whereIn('key', array_unique($keys))
            ->where('locale', $locale)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('key')
            ->map(fn ($items) => $items->first()->value)
            ->toArray();
    }

    /**
     * Extract all translation keys from order_details_data
     */
    private function extractTranslationKeys(array $order_details_data): array
    {
        $keys = [];
        foreach ($order_details_data as $item) {
            foreach ($item['extras'] ?? [] as $el) {
                $keys[] = $el['name'];
            }
            foreach ($item['addons'] ?? [] as $el) {
                $keys[] = $el['addon']['name'] ?? '';
            }
            foreach ($item['excludes'] ?? [] as $el) {
                $keys[] = $el['name'];
            }
            foreach ($item['variations'] ?? [] as $el) {
                $keys[] = $el['variation']['name'] ?? '';
                foreach ($el['options'] ?? [] as $opt) {
                    $keys[] = $opt['name'];
                }
            }
            if (isset($item['product'][0]['product']['name'])) {
                $keys[] = $item['product'][0]['product']['name'];
            }
        }

        return array_filter($keys);
    }

    public function order_details_format($id, $locale)
    {
        $order = Order::with(['user', 'address.zone.city', 'admin:id,name,email,phone,image',
            'branch', 'delivery'])
            ->where('id', $id)
            ->first();
        if (empty($order->order_details_data)) {
            return null;
        }

        // Batch load all translations in ONE query
        $allKeys = $this->extractTranslationKeys($order->order_details_data);
        $translations = $this->batchTranslations($allKeys, $locale);

        $products = [];
        foreach ($order->order_details_data as $item) {
            $extras = [];
            $addons = [];
            $excludes = [];
            $variations = [];
            $product = [];
            foreach ($item['extras'] ?? [] as $element) {
                $extras[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                    'price' => $element['price'] ?? 0,
                ];
            }
            foreach ($item['addons'] ?? [] as $element) {
                $addons[] = [
                    'id' => $element['addon']['id'] ?? $element['id'] ?? null,
                    'name' => $translations[$element['addon']['name'] ?? ''] ?? ($element['addon']['name'] ?? ($element['name'] ?? '')),
                    'price' => $element['addon']['price'] ?? ($element['price'] ?? 0),
                    'count' => $element['count'] ?? 1,
                ];
            }
            foreach ($item['excludes'] ?? [] as $element) {
                $excludes[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                ];
            }
            foreach ($item['variations'] ?? [] as $element) {
                $options = [];
                foreach ($element['options'] ?? [] as $value) {
                    $options[] = [
                        'id' => $value['id'],
                        'name' => $translations[$value['name']] ?? $value['name'],
                        'price' => $value['price'] ?? 0,
                        'total_option_price' => $value['total_option_price'] ?? ($value['price'] ?? 0),
                    ];
                }
                $variations[] = [
                    'id' => $element['variation']['id'] ?? null,
                    'name' => $translations[$element['variation']['name'] ?? ''] ?? ($element['variation']['name'] ?? ''),
                    'options' => $options,
                ];
            }
            if (isset($item['product'][0]['product'])) {
                $prodData = $item['product'][0]['product'];
                $product = [
                    'id' => $prodData['id'] ?? null,
                    'name' => $translations[$prodData['name'] ?? ''] ?? ($prodData['name'] ?? ''),
                    'image_link' => $prodData['image_link'] ?? $prodData['image'] ?? null,
                    'price' => $prodData['price'] ?? 0,
                    'price_after_discount' => $prodData['price_after_discount'] ?? null,
                    'price_after_tax' => $prodData['price_after_tax'] ?? null,
                    'count' => $item['product'][0]['count'] ?? 1,
                    'notes' => $item['product'][0]['notes'] ?? null,
                ];
            }
            $products[] = [
                'extras' => $extras,
                'addons' => $addons,
                'excludes' => $excludes,
                'variations' => $variations,
                'product' => $product,
            ];
        }
        $order_arr = [
            'id' => $order->id,
            'order_details' => $products,
            'amount' => $order->amount,
            'service_fees' => $order->service_fees ?? 0,
            'rate' => $order->rate,
            'comment' => $order->comment,
            'order_status' => $order->order_status,
            'payment' => $order->payment_method_id != 2 ? 'Paid' : 'UnPaid',
            'order_type' => $order->order_type,
            'total_tax' => $order->total_tax,
            'total_discount' => $order->total_discount,
            'notes' => $order->notes,
            'coupon_discount' => $order->coupon_discount,
            'order_number2' => $order->order_number,
            'order_number' => $order->order_number ?? ($order->id - app('first_order_yesterday')),
            'rejected_reason' => $order->rejected_reason,
            'transaction_id' => $order->transaction_id,
            'customer_cancel_reason' => $order->customer_cancel_reason,
            'source' => $order->source,
            'order_date' => $order->created_at->format('Y-m-d'),
            'order_time' => $order->created_at->format('h:i A'),
            'status_payment' => $order->status_payment,
            'service_fees_item' => [
                'id' => $order?->service_fees_item?->id ?? null,
                'type' => $order?->service_fees_item?->type ?? null,
                'amount' => $order?->service_fees_item?->amount ?? null,
            ],
            'branch' => [
                'id' => $order?->branch?->id ?? null,
                'name' => $order?->branch
                    ?->translations()
                    ?->where('key', $order?->branch?->name ?? null)
                    ?->where('locale', $locale)
                    ?->first()
                    ?->value ?? $order?->branch?->name ?? null,
                'address' => $order?->branch?->address ?? null,
                'email' => $order?->branch?->email ?? null,
                'phone' => $order?->branch?->phone ?? null,
            ],
            'user' => isset($order?->user?->id) ? [
                'id' => $order?->user?->id ?? null,
                'f_name' => $order?->user?->f_name ?? null,
                'l_name' => $order?->user?->l_name ?? null,
                'email' => $order?->user?->email ?? null,
                'phone' => $order?->user?->phone ?? null,
                'phone_2' => $order?->user?->phone_2 ?? null,
                'name' => $order?->user?->name ?? null,
            ] : null,
            'admin' => [
                'id' => $order?->admin?->id ?? null,
                'name' => $order?->admin?->name ?? null,
            ],
            'delivery' => [
                'id' => $order?->delivery?->id ?? null,
                'f_name' => $order?->delivery?->f_name ?? null,
                'l_name' => $order?->delivery?->l_name ?? null,
                'phone' => $order?->delivery?->phone ?? null,
            ],
            'address' => [
                'id' => $order?->address?->id ?? null,
                'address' => $order?->address?->address ?? null,
                'street' => $order?->address?->street ?? null,
                'building_num' => $order?->address?->building_num ?? null,
                'floor_num' => $order?->address?->floor_num ?? null,
                'apartment' => $order?->address?->apartment ?? null,
                'additional_data' => $order?->address?->additional_data ?? null,
                'type' => $order?->address?->type ?? null,
                'map' => $order?->address?->map ?? null,
                'zone' => [
                    'zone' => $order?->address?->zone?->zone ?? null,
                    'price' => $order?->address?->zone?->price ?? null,
                ],
                'city' => $order?->address?->zone?->city?->name ?? null,
            ],
        ];

        return $order_arr;
    }

    public function invoice_format($orders, $locale)
    {
        $order_arr = [];
        foreach ($orders as $order) {
            if (empty($order->order_details_data)) {
                continue;
            }

            // Batch load all translations in ONE query per order
            $allKeys = $this->extractTranslationKeys($order->order_details_data);
            $translations = $this->batchTranslations($allKeys, $locale);

            $products = [];
            foreach ($order->order_details_data as $item) {
                $extras = [];
                $addons = [];
                $excludes = [];
                $variations = [];
                $product = [];
                foreach ($item['extras'] ?? [] as $element) {
                    $extras[] = [
                        'id' => $element['id'],
                        'name' => $translations[$element['name']] ?? $element['name'],
                        'price' => $element['price'] ?? 0,
                    ];
                }
                foreach ($item['addons'] ?? [] as $element) {
                    $addons[] = [
                        'id' => $element['addon']['id'] ?? $element['id'] ?? null,
                        'name' => $translations[$element['addon']['name'] ?? ''] ?? ($element['addon']['name'] ?? ($element['name'] ?? '')),
                        'price' => $element['addon']['price'] ?? ($element['price'] ?? 0),
                        'count' => $element['count'] ?? 1,
                    ];
                }
                foreach ($item['excludes'] ?? [] as $element) {
                    $excludes[] = [
                        'id' => $element['id'],
                        'name' => $translations[$element['name']] ?? $element['name'],
                    ];
                }
                foreach ($item['variations'] ?? [] as $element) {
                    $options = [];
                    foreach ($element['options'] ?? [] as $value) {
                        $options[] = [
                            'id' => $value['id'],
                            'name' => $translations[$value['name']] ?? $value['name'],
                            'price' => $value['price'] ?? 0,
                            'total_option_price' => $value['total_option_price'] ?? ($value['price'] ?? 0),
                        ];
                    }
                    $variations[] = [
                        'id' => $element['variation']['id'] ?? null,
                        'name' => $translations[$element['variation']['name'] ?? ''] ?? ($element['variation']['name'] ?? ''),
                        'options' => $options,
                    ];
                }
                if (isset($item['product'][0]['product'])) {
                    $prodData = $item['product'][0]['product'];
                    $product = [
                        'id' => $prodData['id'] ?? null,
                        'name' => $translations[$prodData['name'] ?? ''] ?? ($prodData['name'] ?? ''),
                        'image_link' => $prodData['image_link'] ?? $prodData['image'] ?? null,
                        'price' => $prodData['price'] ?? 0,
                        'price_after_discount' => $prodData['price_after_discount'] ?? null,
                        'price_after_tax' => $prodData['price_after_tax'] ?? null,
                        'count' => $item['product'][0]['count'] ?? 1,
                        'notes' => $item['product'][0]['notes'] ?? null,
                    ];
                }
                $product_item = [
                    'extras' => $extras,
                    'addons' => $addons,
                    'excludes' => $excludes,
                    'variations' => $variations,
                    'product' => $product,
                ];
                $products[] = $product_item;
            }
            $order_arr[] = [
                'id' => $order->id,
                'order_details' => $products,
                'amount' => $order->amount,
                'service_fees' => $order->service_fees ?? 0,
                'rate' => $order->rate,
                'comment' => $order->comment,
                'order_status' => $order->order_status,
                'payment' => $order->payment_method_id != 2 ? 'Paid' : 'UnPaid',
                'order_type' => $order->order_type,
                'total_tax' => $order->total_tax,
                'total_discount' => $order->total_discount,
                'notes' => $order->notes,
                'coupon_discount' => $order->coupon_discount,
                'order_number' => $order->order_number,
                'rejected_reason' => $order->rejected_reason,
                'transaction_id' => $order->transaction_id,
                'financial_accountigs' => $order->financial_accountigs
                    ->map(function ($element) {
                        return [
                            'id' => $element->id,
                            'name' => $element->name,
                        ];
                    }),
                'customer_cancel_reason' => $order->customer_cancel_reason,
                'source' => $order->source,
                'order_date' => $order->created_at->format('Y-m-d'),
                'order_time' => $order->created_at->format('h:i A'),
                'status_payment' => $order->status_payment,
                'service_fees_item' => [
                    'id' => $order?->service_fees_item?->id ?? null,
                    'type' => $order?->service_fees_item?->type ?? null,
                    'amount' => $order?->service_fees_item?->amount ?? null,
                ],
                'branch' => [
                    'id' => $order?->branch?->id ?? null,
                    'name' => $order?->branch
                        ?->translations()
                        ?->where('key', $order?->branch?->name ?? null)
                        ?->where('locale', $locale)
                        ?->first()
                        ?->value ?? $order?->branch?->name ?? null,
                    'address' => $order?->branch?->address ?? null,
                    'email' => $order?->branch?->email ?? null,
                    'phone' => $order?->branch?->phone ?? null,
                ],
                'user' => isset($order?->user?->id) ? [
                    'id' => $order?->user?->id ?? null,
                    'f_name' => $order?->user?->f_name ?? null,
                    'l_name' => $order?->user?->l_name ?? null,
                    'email' => $order?->user?->email ?? null,
                    'phone' => $order?->user?->phone ?? null,
                    'phone_2' => $order?->user?->phone_2 ?? null,
                    'name' => $order?->user?->name ?? null,
                ] : null,
                'admin' => [
                    'id' => $order?->admin?->id ?? null,
                    'name' => $order?->admin?->name ?? null,
                ],
                'delivery' => [
                    'id' => $order?->delivery?->id ?? null,
                    'f_name' => $order?->delivery?->f_name ?? null,
                    'l_name' => $order?->delivery?->l_name ?? null,
                    'phone' => $order?->delivery?->phone ?? null,
                ],
                'address' => [
                    'id' => $order?->address?->id ?? null,
                    'address' => $order?->address?->address ?? null,
                    'street' => $order?->address?->street ?? null,
                    'building_num' => $order?->address?->building_num ?? null,
                    'floor_num' => $order?->address?->floor_num ?? null,
                    'apartment' => $order?->address?->apartment ?? null,
                    'additional_data' => $order?->address?->additional_data ?? null,
                    'type' => $order?->address?->type ?? null,
                    'map' => $order?->address?->map ?? null,
                    'zone' => [
                        'zone' => $order?->address?->zone?->zone ?? null,
                        'price' => $order?->address?->zone?->price ?? null,
                    ],
                    'city' => $order?->address?->zone?->city?->name ?? null,
                ],
            ];
        }

        return $order_arr;
    }

    // ___________________________________________________________

    public function order_item_format($order, $id, $locale)
    {
        if (empty($order->order_details_data)) {
            return null;
        }

        // Batch load all translations in ONE query
        $allKeys = $this->extractTranslationKeys($order->order_details_data);
        $translations = $this->batchTranslations($allKeys, $locale);

        $products = [];
        foreach ($order->order_details_data as $item) {
            $extras = [];
            $addons = [];
            $excludes = [];
            $variations = [];
            $product = [];
            foreach ($item['extras'] ?? [] as $element) {
                $extras[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                    'price' => $element['price'] ?? 0,
                ];
            }
            foreach ($item['addons'] ?? [] as $element) {
                $addons[] = [
                    'id' => $element['addon']['id'] ?? $element['id'] ?? null,
                    'name' => $translations[$element['addon']['name'] ?? ''] ?? ($element['addon']['name'] ?? ($element['name'] ?? '')),
                    'price' => $element['addon']['price'] ?? ($element['price'] ?? 0),
                    'count' => $element['count'] ?? 1,
                ];
            }
            foreach ($item['excludes'] ?? [] as $element) {
                $excludes[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                ];
            }
            foreach ($item['variations'] ?? [] as $element) {
                $options = [];
                foreach ($element['options'] ?? [] as $value) {
                    $options[] = [
                        'id' => $value['id'],
                        'name' => $translations[$value['name']] ?? $value['name'],
                        'price' => $value['price'] ?? 0,
                        'total_option_price' => $value['total_option_price'] ?? ($value['price'] ?? 0),
                    ];
                }
                $variations[] = [
                    'id' => $element['variation']['id'] ?? null,
                    'name' => $translations[$element['variation']['name'] ?? ''] ?? ($element['variation']['name'] ?? ''),
                    'options' => $options,
                ];
            }
            if (isset($item['product'][0]['product'])) {
                $prodData = $item['product'][0]['product'];
                $product = [
                    'id' => $prodData['id'] ?? null,
                    'name' => $translations[$prodData['name'] ?? ''] ?? ($prodData['name'] ?? ''),
                    'image_link' => $prodData['image_link'] ?? $prodData['image'] ?? null,
                    'price' => $prodData['price'] ?? 0,
                    'price_after_discount' => $prodData['price_after_discount'] ?? null,
                    'price_after_tax' => $prodData['price_after_tax'] ?? null,
                    'count' => $item['product'][0]['count'] ?? 1,
                    'notes' => $item['product'][0]['notes'] ?? null,
                ];
            }
            $products[] = [
                'extras' => $extras,
                'addons' => $addons,
                'excludes' => $excludes,
                'variations' => $variations,
                'product' => $product,
            ];
        }
        $order_arr = [
            'id' => $order->id,
            'order_details' => $products,
            'amount' => $order->amount,
            'comment' => $order->comment,
            'coupon_discount' => $order->coupon_discount ?? null,
            'rate' => $order->rate,
            'order_status' => $order->order_status,
            'payment' => $order->payment_method_id !== 2 ? 'Paid' : 'UnPaid',
            'order_type' => $order->order_type,
            'total_tax' => $order->total_tax,
            'total_discount' => $order->total_discount,
            'notes' => $order->notes,
            'coupon_discount' => $order->coupon_discount,
            'order_number' => $order->order_number,
            'rejected_reason' => $order->rejected_reason,
            'transaction_id' => $order->transaction_id,
            'customer_cancel_reason' => $order->customer_cancel_reason,
            'admin_cancel_reason' => $order->admin_cancel_reason,
            'source' => $order->source,
            'service_fees' => $order->service_fees ?? 0,
            'order_date' => $order->created_at->format('Y-m-d'),
            'order_time' => $order->created_at->format('h:i A'),
            'status_payment' => $order->status_payment,
            'service_fees_item' => [
                'id' => $order?->service_fees_item?->id ?? null,
                'type' => $order?->service_fees_item?->type ?? null,
                'amount' => $order?->service_fees_item?->amount ?? null,
            ],
            'branch' => [
                'id' => $order?->branch?->id ?? null,
                'name' => $order?->branch
                    ?->translations()
                    ?->where('key', $order?->branch?->name)
                    ?->where('locale', $locale)
                    ?->first()
                    ?->value ?? $order?->branch?->name ?? null,
                'address' => $order?->branch?->address ?? null,
                'email' => $order?->branch?->email ?? null,
                'phone' => $order?->branch?->phone ?? null,
                'count_orders' => $order?->branch?->count_orders ?? null,
            ],
            'user' => isset($order?->user?->id) ? [
                'id' => $order?->user?->id ?? null,
                'f_name' => $order?->user?->f_name ?? null,
                'l_name' => $order?->user?->l_name ?? null,
                'email' => $order?->user?->email ?? null,
                'phone' => $order?->user?->phone ?? null,
                'phone_2' => $order?->user?->phone_2 ?? null,
                'name' => $order?->user?->name ?? null,
                'count_orders' => $order?->user?->count_orders ?? null,
            ] : null,
            'admin' => [
                'id' => $order?->admin?->id ?? null,
                'name' => $order?->admin?->name ?? null,
            ],
            'delivery' => [
                'id' => $order?->delivery?->id ?? null,
                'f_name' => $order?->delivery?->f_name ?? null,
                'l_name' => $order?->delivery?->l_name ?? null,
                'phone' => $order?->delivery?->phone ?? null,
                'count_orders' => $order?->delivery?->count_orders ?? null,
            ],
            'address' => [
                'id' => $order?->address?->id ?? null,
                'address' => $order?->address?->address ?? null,
                'street' => $order?->address?->street ?? null,
                'building_num' => $order?->address?->building_num ?? null,
                'floor_num' => $order?->address?->floor_num ?? null,
                'apartment' => $order?->address?->apartment ?? null,
                'additional_data' => $order?->address?->additional_data ?? null,
                'type' => $order?->address?->type ?? null,
                'map' => $order?->address?->map ?? null,
                'zone' => [
                    'zone' => $order?->address?->zone?->zone ?? null,
                    'price' => $order->delivery_fees ?? $order?->address?->zone?->price ?? null,
                ],
                'city' => $order?->address?->zone?->city?->name ?? null,
            ],
            'payment_method' => [
                'id' => $order?->payment_method?->id ?? null,
                'name' => $order?->payment_method?->name ?? null,
                'logo' => $order?->payment_method?->logo_link ?? null,
            ],
            'schedule' => $order?->schedule?->name ?? null,
        ];

        return $order_arr;
    }

    public function order_preparation_format($order, $locale)
    {

        $products = [];
        if (empty($order->order_details_data)) {
            return null;
        }

        // Batch load all translations in ONE query
        $allKeys = $this->extractTranslationKeys($order->order_details_data);
        $translations = $this->batchTranslations($allKeys, $locale);

        foreach ($order->order_details_data as $item) {
            $extras = [];
            $addons = [];
            $excludes = [];
            $variations = [];
            $product = [];
            foreach ($item['extras'] ?? [] as $element) {
                $extras[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                    'price' => $element['price'] ?? 0,
                ];
            }
            foreach ($item['addons'] ?? [] as $element) {
                $addons[] = [
                    'id' => $element['addon']['id'] ?? $element['id'] ?? null,
                    'name' => $translations[$element['addon']['name'] ?? ''] ?? ($element['addon']['name'] ?? ($element['name'] ?? '')),
                    'price' => $element['addon']['price'] ?? ($element['price'] ?? 0),
                    'count' => $element['count'] ?? 1,
                ];
            }
            foreach ($item['excludes'] ?? [] as $element) {
                $excludes[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                ];
            }
            foreach ($item['variations'] ?? [] as $element) {
                $options = [];
                foreach ($element['options'] ?? [] as $value) {
                    $options[] = [
                        'id' => $value['id'],
                        'name' => $translations[$value['name']] ?? $value['name'],
                    ];
                }
                $variations[] = [
                    'id' => $element['variation']['id'] ?? null,
                    'name' => $translations[$element['variation']['name'] ?? ''] ?? ($element['variation']['name'] ?? ''),
                    'options' => $options,
                ];
            }
            if (isset($item['product'][0]['product'])) {
                $product = [
                    'id' => $item['product'][0]['product']['id'],
                    'note' => $item['product'][0]['product']['note'] ?? null,
                    'name' => $translations[$item['product'][0]['product']['name']] ?? $item['product'][0]['product']['name'],
                    'count' => $item['product'][0]['count'],
                ];
            }
            $products[] = [
                'extras' => $extras,
                'addons' => $addons,
                'excludes' => $excludes,
                'variations' => $variations,
                'product' => $product,
            ];
        }
        $order_arr = [
            'id' => $order->id,
            'order_details' => $products,
            'order_type' => $order->order_type,
            'take_away_status' => $order->take_away_status,
            'delivery_status' => $order->delivery_status,
            'order_number' => $order->order_number ?? ($order->id > 1000000000 ? (int) substr((string) $order->id, -4) : ($order->id - app('first_order_today'))),
        ];

        return $order_arr;
    }

    // ___________________________________________________________

    public function checkout_format($order, $id, $locale)
    {
        if (empty($order->order_details_data)) {
            return null;
        }
        $precentage = 0;
        $group_product = GroupProduct::where('id', $order->module_id)
            ->first();
        if (! empty($group_product)) {
            $precentage = $group_product->increase_precentage - $group_product->decrease_precentage;
        }

        // Batch load all translations in ONE query
        $allKeys = $this->extractTranslationKeys($order->order_details_data);
        $translations = $this->batchTranslations($allKeys, $locale);

        $products = [];
        $total_price_calculated = 0;
        foreach ($order->order_details_data as $item) {
            $extras = [];
            $addons = [];
            $excludes = [];
            $variations = [];
            $product = [];
            $product_price = 0;
            foreach ($item['extras'] ?? [] as $element) {
                $extra_price_item = GroupExtraPrice::where('extra_id', $element['id'])
                    ->where('group_product_id', $order->module_id)
                    ->first();
                if (! empty($extra_price_item)) {
                    $extra_price = $extra_price_item->price;
                } else {
                    $extra_price = ($element['price'] ?? 0) + ($element['price'] ?? 0) * $precentage / 100;
                }
                $product_price += $extra_price;
                $extras[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                ];
            }
            foreach ($item['excludes'] ?? [] as $element) {
                $name = TranslationTbl::where('key', $element['name'])
                    ->where('locale', $locale)
                    ->orderByDesc('created_at')
                    ->first()?->value ?? $element['name'];
                $excludes[] = [
                    'id' => $element['id'],
                    'name' => $name,
                ];
            }
            foreach ($item['addons'] ?? [] as $element) {
                $addon_id = $element['addon']['id'] ?? $element['id'] ?? null;
                $addon_raw_price = $element['addon']['price'] ?? ($element['price'] ?? 0);
                $addon_name = $element['addon']['name'] ?? ($element['name'] ?? '');
                $addon_count = $element['count'] ?? 1;

                $addon_price_item = GroupAddonPrice::where('addon_id', $addon_id)
                    ->where('group_product_id', $order->module_id)
                    ->first();
                if (! empty($addon_price_item)) {
                    $addon_price = $addon_price_item->price;
                } else {
                    $addon_price = $addon_raw_price + $addon_raw_price * $precentage / 100;
                }
                $addons[] = [
                    'id' => $addon_id,
                    'name' => $translations[$addon_name] ?? $addon_name,
                    'price' => $addon_price,
                    'count' => $addon_count,
                    'total' => $addon_price * $addon_count,
                ];
                $total_price_calculated += $addon_price * $addon_count;
            }
            foreach ($item['variations'] ?? [] as $element) {
                $options = [];
                foreach ($element['options'] ?? [] as $value) {
                    $options[] = [
                        'id' => $value['id'],
                        'name' => $translations[$value['name']] ?? $value['name'],
                        'price' => $value['price'] ?? 0,
                        'total_option_price' => $value['total_option_price'] ?? ($value['price'] ?? 0),
                    ];
                    $option_price_item = GroupOptionPrice::where('option_id', $value['id'])
                        ->where('group_product_id', $order->module_id)
                        ->first();
                    if (! empty($option_price_item)) {
                        $price = $option_price_item->price;
                    } else {
                        $price = ($value['price'] ?? 0) + ($value['price'] ?? 0) * $precentage / 100;
                    }
                    $product_price += $price;
                }
                $variations[] = [
                    'id' => $element['variation']['id'] ?? null,
                    'name' => $translations[$element['variation']['name'] ?? ''] ?? ($element['variation']['name'] ?? ''),
                    'options' => $options,
                ];
            }
            if (isset($item['product'][0]['product'])) {
                $prodData = $item['product'][0]['product'];
                $price = $prodData['price'] ?? 0;
                $count = $item['product'][0]['count'] ?? 1;
                $pId = $prodData['id'] ?? null;
                $product_price_item = $pId ? GroupPrice::where('product_id', $pId)
                    ->where('group_product_id', $order->module_id)
                    ->first() : null;
                if (! empty($product_price_item)) {
                    $price = $product_price_item->price;
                } else {
                    $price = $price + $price * $precentage / 100;
                }
                $product_price += $price;
                $product = [
                    'id' => $pId,
                    'name' => $translations[$prodData['name'] ?? ''] ?? ($prodData['name'] ?? ''),
                    'price' => $product_price,
                    'total_price' => $product_price * $count,
                    'count' => $count,
                ];
                $total_price_calculated += $product_price * $count;
            }
            $product_item = [
                'extras' => $extras,
                'addons' => $addons,
                'excludes' => $excludes,
                'variations' => $variations,
                'product' => $product,
            ];
            $products[] = $product_item;
        }
        $order_arr = [
            'id' => $order->id,
            'order_details' => $products,
            'amount' => $order->amount,
            'order_status' => $order->order_status,
            'payment' => $order->payment_method_id !== 2 ? 'Paid' : 'UnPaid',
            'order_type' => $order->order_type,
            'total_tax' => $order->total_tax,
            'total_discount' => $order->total_discount,
            'coupon_discount' => $order->coupon_discount,
            'order_number' => $order->order_number ?? ($order->id > 1000000000 ? (int) substr((string) $order->id, -4) : ($order->id - app('first_order_today'))),
            'order_date' => $order->created_at->format('Y-m-d'),
            'order_time' => $order->created_at->format('h:i A'),
            'date' => $order->created_at,
            'status_payment' => $order->status_payment,
            'delivery_fees' => $order?->address?->zone?->price ?? null,
            'branch' => [
                'id' => $order?->branch?->id ?? null,
                'name' => $order?->branch
                    ?->translations()
                    ?->where('key', $order?->branch?->name)
                    ?->where('locale', $locale)
                    ?->first()
                    ?->value ?? $order?->branch?->name ?? null,
                'address' => $order?->branch?->address ?? null,
                'email' => $order?->branch?->email ?? null,
                'phone' => $order?->branch?->phone ?? null,
                'count_orders' => $order?->branch?->count_orders ?? null,
            ],
            'user' => isset($order?->user?->id) ? [
                'id' => $order?->user?->id ?? null,
                'f_name' => $order?->user?->f_name ?? null,
                'l_name' => $order?->user?->l_name ?? null,
                'email' => $order?->user?->email ?? null,
                'phone' => $order?->user?->phone ?? null,
                'phone_2' => $order?->user?->phone_2 ?? null,
                'name' => $order?->user?->name ?? null,
                'count_orders' => $order?->user?->count_orders ?? null,
            ] : null,
            'admin' => [
                'id' => $order?->admin?->id ?? null,
                'name' => $order?->admin?->name ?? null,
            ],
            'delivery' => [
                'id' => $order?->delivery?->id ?? null,
                'f_name' => $order?->delivery?->f_name ?? null,
                'l_name' => $order?->delivery?->l_name ?? null,
                'phone' => $order?->delivery?->phone ?? null,
                'count_orders' => $order?->delivery?->count_orders ?? null,
            ],
            'address' => [
                'id' => $order?->address?->id ?? null,
                'address' => $order?->address?->address ?? null,
                'street' => $order?->address?->street ?? null,
                'building_num' => $order?->address?->building_num ?? null,
                'floor_num' => $order?->address?->floor_num ?? null,
                'apartment' => $order?->address?->apartment ?? null,
                'additional_data' => $order?->address?->additional_data ?? null,
                'type' => $order?->address?->type ?? null,
                'map' => $order?->address?->map ?? null,
                'zone' => [
                    'zone' => $order?->address?->zone?->zone ?? null,
                    'price' => $order?->address?->zone?->price ?? null,
                ],
                'city' => $order?->address?->zone?->city?->name ?? null,
            ],
        ];

        return $order_arr;
    }

    public function main_order_details_format($id, $locale)
    {
        $order = Order::with(['user', 'address.zone.city', 'admin:id,name,email,phone,image',
            'branch', 'delivery'])
            ->where('id', $id)
            ->first();
        if (empty($order->order_details_data)) {
            return null;
        }

        // Batch load all translations in ONE query
        $allKeys = $this->extractTranslationKeys($order->order_details_data);
        $translations = $this->batchTranslations($allKeys, $locale);

        $products = [];
        foreach ($order->order_details_data as $item) {
            $extras = [];
            $addons = [];
            $excludes = [];
            $variations = [];
            $product = [];
            foreach ($item['extras'] ?? [] as $element) {
                $extras[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                    'price' => $element['price'] ?? 0,
                ];
            }
            foreach ($item['addons'] ?? [] as $element) {
                $addons[] = [
                    'id' => $element['addon']['id'] ?? $element['id'] ?? null,
                    'name' => $translations[$element['addon']['name'] ?? ''] ?? ($element['addon']['name'] ?? ($element['name'] ?? '')),
                    'price' => $element['addon']['price'] ?? ($element['price'] ?? 0),
                    'count' => $element['count'] ?? 1,
                ];
            }
            foreach ($item['excludes'] ?? [] as $element) {
                $excludes[] = [
                    'id' => $element['id'],
                    'name' => $translations[$element['name']] ?? $element['name'],
                ];
            }
            foreach ($item['variations'] ?? [] as $element) {
                $options = [];
                foreach ($element['options'] ?? [] as $value) {
                    $options[] = [
                        'id' => $value['id'],
                        'name' => $translations[$value['name']] ?? $value['name'],
                        'price' => $value['price'] ?? 0,
                        'total_option_price' => $value['total_option_price'] ?? ($value['price'] ?? 0),
                    ];
                }
                $variations[] = [
                    'id' => $element['variation']['id'] ?? null,
                    'name' => $translations[$element['variation']['name'] ?? ''] ?? ($element['variation']['name'] ?? ''),
                    'options' => $options,
                ];
            }
            if (isset($item['product'][0]['product'])) {
                $prodData = $item['product'][0]['product'];
                $product = [
                    'id' => $prodData['id'] ?? null,
                    'name' => $translations[$prodData['name'] ?? ''] ?? ($prodData['name'] ?? ''),
                    'image_link' => $prodData['image_link'] ?? $prodData['image'] ?? null,
                    'price' => $prodData['price'] ?? 0,
                    'price_after_discount' => $prodData['price_after_discount'] ?? null,
                    'price_after_tax' => $prodData['price_after_tax'] ?? null,
                    'count' => $item['product'][0]['count'] ?? 1,
                    'notes' => $item['product'][0]['notes'] ?? null,
                ];
            }
            $products[] = [
                'extras' => $extras,
                'addons' => $addons,
                'excludes' => $excludes,
                'variations' => $variations,
                'product' => $product,
            ];
        }
        $order_arr = [
            'id' => $order->id,
            'order_details' => $products,
            'amount' => $order->amount,
            'service_fees' => $order->service_fees ?? 0,
            'rate' => $order->rate,
            'comment' => $order->comment,
            'order_status' => $order->order_status,
            'payment' => $order->payment_method_id != 2 ? 'Paid' : 'UnPaid',
            'order_type' => $order->order_type,
            'total_tax' => $order->total_tax,
            'total_discount' => $order->total_discount,
            'notes' => $order->notes,
            'coupon_discount' => $order->coupon_discount,
            'order_number2' => $order->order_number,
            'order_number' => $order->order_number ?? ($order->id - app('first_order_yesterday')),
            'rejected_reason' => $order->rejected_reason,
            'transaction_id' => $order->transaction_id,
            'customer_cancel_reason' => $order->customer_cancel_reason,
            'source' => $order->source,
            'order_date' => $order->created_at->format('Y-m-d'),
            'order_time' => $order->created_at->format('h:i A'),
            'status_payment' => $order->status_payment,
            'service_fees_item' => [
                'id' => $order?->service_fees_item?->id ?? null,
                'type' => $order?->service_fees_item?->type ?? null,
                'amount' => $order?->service_fees_item?->amount ?? null,
            ],
            'branch' => [
                'id' => $order?->branch?->id ?? null,
                'name' => $order?->branch
                    ?->translations()
                    ?->where('key', $order?->branch?->name ?? null)
                    ?->where('locale', $locale)
                    ?->first()
                    ?->value ?? $order?->branch?->name ?? null,
                'address' => $order?->branch?->address ?? null,
                'email' => $order?->branch?->email ?? null,
                'phone' => $order?->branch?->phone ?? null,
            ],
            'user' => isset($order?->user?->id) ? [
                'id' => $order?->user?->id ?? null,
                'f_name' => $order?->user?->f_name ?? null,
                'l_name' => $order?->user?->l_name ?? null,
                'email' => $order?->user?->email ?? null,
                'phone' => $order?->user?->phone ?? null,
                'phone_2' => $order?->user?->phone_2 ?? null,
                'name' => $order?->user?->name ?? null,
            ] : null,
            'admin' => [
                'id' => $order?->admin?->id ?? null,
                'name' => $order?->admin?->name ?? null,
            ],
            'delivery' => [
                'id' => $order?->delivery?->id ?? null,
                'f_name' => $order?->delivery?->f_name ?? null,
                'l_name' => $order?->delivery?->l_name ?? null,
                'phone' => $order?->delivery?->phone ?? null,
            ],
            'address' => [
                'id' => $order?->address?->id ?? null,
                'address' => $order?->address?->address ?? null,
                'street' => $order?->address?->street ?? null,
                'building_num' => $order?->address?->building_num ?? null,
                'floor_num' => $order?->address?->floor_num ?? null,
                'apartment' => $order?->address?->apartment ?? null,
                'additional_data' => $order?->address?->additional_data ?? null,
                'type' => $order?->address?->type ?? null,
                'map' => $order?->address?->map ?? null,
                'zone' => [
                    'zone' => $order?->address?->zone?->zone ?? null,
                    'price' => $order?->address?->zone?->price ?? null,
                ],
                'city' => $order?->address?->zone?->city?->name ?? null,
            ],
        ];

        return $order_arr;
    }
}
