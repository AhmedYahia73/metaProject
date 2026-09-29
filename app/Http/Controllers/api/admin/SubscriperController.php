<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Order;

class SubscriperController extends Controller
{
    public function subscripers(){

        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'user_id' => 'sometimes|exists:users,id',
            'package_id' => 'sometimes|exists:packages,id',
            'search' => 'sometimes|string|max:255',
            'channel' => 'sometimes|in:whatsapp,messenger',
        ]);

        $query = Order::with(['package:id,name', 'user:id,name,phone', 
        'whatsItem:id,phone', 'messengerAccount:id,page_name'])->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('package_id')) {
            $query->where('package_id', $request->package_id);
        }

        $query->where('status', "approved");

        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('id', $search)
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $request->integer('per_page', 15);

        $transform = function ($order) {
            $send_msgs = MsgSend::
            whereDate("created_at", ">=", $order->from)
            ->whereDate("created_at", "<=", $order->to)
            ->count();
            return [
                'id' => $order->id,
                'package_name' => $order->package?->name,
                'package' => [
                    'id' => $order->package?->id,
                    'name' => $order->package?->name,
                ],
                'user_id' => $order->user_id,
                'user_name' => $order->user?->name,
                'user_phone' => $order->user?->phone,
                'user' => [
                    'id' => $order->user?->id,
                    'name' => $order->user?->name,
                    'phone' => $order->user?->phone,
                ],
                'total_discount' => (float) $order->total_discount,
                'total_tax' => (float) $order->total_tax,
                'price' => (float) $order->price,
                'final_price' => (float) $order->final_price,
                'msgs' => (int) $order->msgs,
                'from' => $order->from ? Carbon::parse($order->from)->toDateString() : null,
                'to' => $order->to ? Carbon::parse($order->to)->toDateString() : null,
                'status' => $order->status,
                'channel' => $order->channel,
                'messenger_account_id' => $order->messenger_account_id,
                'whats_item_id' => $order->whats_item_id,
                'whats_item' => $order->whatsItem ? [
                    'id' => $order->whatsItem->id,
                    'phone' => $order->whatsItem->phone,
                ] : null,
                'messengerAccount' => [
                    'id' => $order->id,
                    'page_name' => $order->page_name,
                ],
                'created_at' => $order->created_at,
                "send_msgs" => $send_msgs,
                "available_msgs" => (int) $order->msgs - $send_msgs,
            ];
        };
 
        $orders = $query->paginate($perPage);
        $orders->through($transform);

        return response()->json([
            'status' => true,
            'data' => $orders,
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
                'has_more' => $orders->hasMorePages(),
            ],
        ]); 

        $orders = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'data' => $orders,
        ]);
    }
}
