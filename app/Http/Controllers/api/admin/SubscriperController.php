<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use App\Models\MsgSend;
use App\Models\Order;

class SubscriperController extends Controller
{
    public function subscripers(Request $request){

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
           
                'user_id' => $order->user_id,
                'user_name' => $order->user?->name,
                'user_phone' => $order->user?->phone,
         
                'total_discount' => (float) $order->total_discount,
                'total_tax' => (float) $order->total_tax,
                'price' => (float) $order->price,
                'final_price' => (float) $order->final_price,
                'msgs' => (int) $order->msgs,
                'from' => $order->from ? Carbon::parse($order->from)->toDateString() : null,
                'to' => $order->to ? Carbon::parse($order->to)->toDateString() : null,
                'channel' => $order->channel,
                'whats_item' => $order->whatsItem ? [
                    'id' => $order->whatsItem->id,
                    'phone' => $order->whatsItem->phone,
                ] : null,
                'messengerAccount' => [
                    'id' => $order->id,
                    'page_name' => $order->page_name,
                ],
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
