<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Display dashboard statistics (message consumption, active subscriptions, and overview).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'sometimes|nullable|exists:users,id',
            'from'    => 'sometimes|nullable|date',
            'to'      => 'sometimes|nullable|date|after_or_equal:from',
        ]);

        $today = now()->toDateString();

        // 1. Build Orders query
        $orderQuery = Order::query();

        if ($request->filled('user_id')) {
            $orderQuery->where('user_id', $request->user_id);
        }

        if ($request->filled('from') && $request->filled('to')) {
            $orderQuery->where('from', '<=', $request->to)
                       ->where('to', '>=', $request->from);
        } elseif ($request->filled('from')) {
            $orderQuery->where('to', '>=', $request->from);
        } elseif ($request->filled('to')) {
            $orderQuery->where('from', '<=', $request->to);
        } else {
            // Default: orders active today
            $orderQuery->where('from', '<=', $today)
                       ->where('to', '>=', $today);
        }

        // Calculate total allocated messages and effective date range
        $totalAllocatedMsgs = (int) (clone $orderQuery)->sum('msgs');
        $periodFrom = (clone $orderQuery)->min('from') ?? $request->from ?? $today;
        $periodTo   = (clone $orderQuery)->max('to') ?? $request->to ?? $today;

        // 2. Build MsgSend query
        $msgSendQuery = MsgSend::query();

        if ($request->filled('user_id')) {
            $msgSendQuery->where('user_id', $request->user_id);
        }

        if ($periodFrom) {
            $msgSendQuery->whereDate('created_at', '>=', $periodFrom);
        }

        if ($periodTo) {
            $msgSendQuery->whereDate('created_at', '<=', $periodTo);
        }

        $used = $msgSendQuery->count();
        $remaining = max(0, $totalAllocatedMsgs - $used);

        // 3. Optional overview metrics for general admin dashboard (when user_id is not specified)
        $overview = [];
        if (! $request->filled('user_id')) {
            $overview = [
                'total_restaurants'   => User::where('role', 'user')->count(),
                'active_restaurants'  => Order::where('from', '<=', $today)
                                            ->where('to', '>=', $today)
                                            ->distinct('user_id')
                                            ->count('user_id'),
                'total_orders'        => Order::count(),
            ];
        } else {
            $targetUser = User::find($request->user_id);
            $overview = [
                'restaurant_name' => $targetUser?->restuarant_name,
                'phone'           => $targetUser?->phone,
                'phone_status'    => $targetUser?->phone_status,
            ];
        }

        return response()->json([
            'status'  => true,
            'message' => 'Admin dashboard data',
            'data'    => [
                'active_order' => $totalAllocatedMsgs,
                'used'         => $used,
                'remaining'    => $remaining,
                'period'       => [
                    'from' => $periodFrom,
                    'to'   => $periodTo,
                ],
                'overview'     => $overview,
            ],
        ]);
    }
}
