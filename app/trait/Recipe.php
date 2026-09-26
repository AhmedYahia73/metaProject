<?php

namespace App\trait;

use App\Models\Product;
use App\Models\PurchaseStock;

trait Recipe
{
    public function pull_recipe($products, $branch_id)
    {
        foreach ($products as $item) {
            $product = Product::where('id', $item['id'])
                ->with('unit:id,name', 'recipes')
                ->first();
            if (empty($product)) {
                return [
                    'success' => false,
                    'msg' => $item['id'].' id is wrong',
                ];
            }
            if ($product->recipe) {
                $recipes = $product->recipes;
                foreach ($recipes as $element) {
                    $stock = PurchaseStock::where('product_id', $element->store_product_id)
                        ->whereHas('store', function ($query) use ($branch_id) {
                            $query->whereHas('branches', function ($q) use ($branch_id) {
                                $q->where('branches.id', $branch_id);
                            });
                        })
                        ->with('unit')
                        ->first();
                    if (empty($stock)) {
                        return [
                            'success' => false,
                            'msg' => 'Recipe '.$element?->store_product?->name.'not enough',
                        ];
                    }

                    if ($product->weight_status) {
                        if ($product->unit_id == $stock->unit_id) {
                            $count = $item['count'];
                            $stock->quantity -= $count;
                            $stock->actual_quantity -= $count;
                        } else {
                            if ($stock?->unit?->name == 'Kg' && $product?->unit?->name == 'Gram') {
                                $count = $item['count'] / 1000;
                            } elseif ($stock?->unit?->name == 'Gram' && $product?->unit?->name == 'Kg') {
                                $count = $item['count'] * 1000;
                            } else {
                                $count = $item['count'];
                            }
                            $stock->quantity -= $count;
                            $stock->actual_quantity -= $count;
                        }
                    } else {
                        $count = $item['count'];
                        $stock->quantity -= $count;
                        $stock->actual_quantity -= $count;
                    }
                    if ($count > $stock->quantity) {
                        return [
                            'success' => false,
                            'msg' => 'Recipe '.$element?->store_product?->name.'not enough',
                        ];
                    }
                    $stock->save();
                }
            }
        }

        return [
            'success' => true,
        ];
    }
}
