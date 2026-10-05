<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Orders & Revenue Report.
     *
     * Returns:
     * - Total final_price across all orders.
     * - Total final_price for every channel as key => total (whatsapp, messenger, instagram).
     * - Total final_price & orders count for every package.
     * - Filterable by date range (from, to) and status.
     */
    public function order_report(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|string|in:approved,pending,rejected,faild,failed,all',
        ]);

        $query = Order::query();

        // Status filter (defaults to approved)
        if ($request->filled('status')) {
            if ($request->status !== 'all') {
                $query->where('status', $request->status);
            }
        } else {
            $query->where('status', 'approved');
        }

        // Date range filter
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        // Overall totals
        $totalFinalPrice = (float) ((clone $query)->sum('final_price') ?? 0);
        $totalOrdersCount = (int) ((clone $query)->count());

        // Channel totals (key => total)
        $channelGrouped = (clone $query)
            ->select('channel', DB::raw('SUM(final_price) as total'))
            ->whereNotNull('channel')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->toArray();

        $channels = [
            'whatsapp' => (float) ($channelGrouped['whatsapp'] ?? 0),
            'messenger' => (float) ($channelGrouped['messenger'] ?? 0),
            'instagram' => (float) ($channelGrouped['instagram'] ?? 0),
        ];

        // Package totals (sum final_price and order count for every package)
        $packagesData = (clone $query)
            ->whereNotNull('package_id')
            ->select('package_id', DB::raw('SUM(final_price) as total_final_price'), DB::raw('COUNT(*) as orders_count'))
            ->groupBy('package_id')
            ->with('package:id,name,price,type')
            ->get()
            ->map(function ($item) {
                $name = $item->package?->name;
                $locale = app()->getLocale();
                $displayName = is_array($name)
                    ? ($name[$locale] ?? $name['ar'] ?? $name['en'] ?? reset($name) ?: 'Unknown')
                    : ($name ?? 'Unknown');

                return [
                    'package_id' => $item->package_id,
                    'package_name' => $displayName,
                    'package_name_raw' => $name,
                    'package_type' => $item->package?->type,
                    'total_final_price' => (float) $item->total_final_price,
                    'orders_count' => (int) $item->orders_count,
                ];
            });

        // Key => total summary for packages
        $packagesSummary = [];
        foreach ($packagesData as $pkg) {
            $packagesSummary[$pkg['package_name']] = $pkg['total_final_price'];
        }

        return response()->json([
            'status' => true,
            'message' => 'Orders revenue report retrieved successfully.',
            'data' => [
                'total_final_price' => $totalFinalPrice,
                'total_orders' => $totalOrdersCount,
                'channels' => $channels,
                'packages' => $packagesData,
                'packages_summary' => $packagesSummary,
                'period' => [
                    'from' => $request->from,
                    'to' => $request->to,
                ],
                'status_filter' => $request->status ?? 'approved',
            ],
        ]);
    }

    /**
     * Message Consumption Report.
     *
     * Returns:
     * - Total messages sent.
     * - Total messages sent from every channel (whatsapp, messenger, instagram).
     * - Filterable by date range (from, to), channel, and user_id.
     */
    public function message_report(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'channel' => 'nullable|string|in:whatsapp,messenger,instagram',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $baseQuery = MsgSend::query();

        // Date range filter
        if ($request->filled('from')) {
            $baseQuery->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $baseQuery->whereDate('created_at', '<=', $request->to);
        }

        // Optional user filter
        if ($request->filled('user_id')) {
            $baseQuery->where('user_id', $request->user_id);
        }

        // Channel totals (key => count)
        $channelGrouped = (clone $baseQuery)
            ->select('channel', DB::raw('COUNT(*) as total'))
            ->whereNotNull('channel')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->toArray();

        $channels = [
            'whatsapp' => (int) ($channelGrouped['whatsapp'] ?? 0),
            'messenger' => (int) ($channelGrouped['messenger'] ?? 0),
            'instagram' => (int) ($channelGrouped['instagram'] ?? 0),
        ];

        // Specific channel query if requested
        $totalMessages = $request->filled('channel')
            ? (int) ($channels[$request->channel] ?? 0)
            : (int) ((clone $baseQuery)->count());

        return response()->json([
            'status' => true,
            'message' => 'Message consumption report retrieved successfully.',
            'data' => [
                'total_messages' => $totalMessages,
                'channels' => $channels,
                'period' => [
                    'from' => $request->from,
                    'to' => $request->to,
                ],
                'channel_filter' => $request->channel,
            ],
        ]);
    }

    /**
     * Subscribers Spending Report.
     *
     * Returns subscribers ordered descending by total money paid (orders sum final_price).
     * Filterable by date range (from, to), channel, search keyword, and pagination.
     */
    public function subscribers_report(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'channel' => 'nullable|string|in:whatsapp,messenger,instagram',
            'status' => 'nullable|string|in:approved,pending,rejected,faild,failed,all',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'paginate' => 'nullable|boolean',
            'paid_only' => 'nullable|boolean',
        ]);

        $status = $request->input('status', 'approved');

        $orderFilter = function ($q) use ($request, $status) {
            if ($status !== 'all') {
                $q->where('status', $status);
            }
            if ($request->filled('from')) {
                $q->whereDate('created_at', '>=', $request->from);
            }
            if ($request->filled('to')) {
                $q->whereDate('created_at', '<=', $request->to);
            }
            if ($request->filled('channel')) {
                $q->where('channel', $request->channel);
            }
        };

        $usersQuery = User::where('role', 'user')
            ->withSum(['orders as total_paid' => $orderFilter], 'final_price')
            ->withCount(['orders as orders_count' => $orderFilter]);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $usersQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('restuarant_name', 'like', "%{$search}%");
            });
        }

        // Only include users who actually placed matching orders (default: true)
        if ($request->boolean('paid_only', true)) {
            $usersQuery->whereHas('orders', $orderFilter);
        }

        $usersQuery->orderByDesc('total_paid');

        $formatUser = function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'restuarant_name' => $user->restuarant_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'total_paid' => (float) ($user->total_paid ?? 0),
                'orders_count' => (int) ($user->orders_count ?? 0),
            ];
        };

        $shouldPaginate = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $result = $shouldPaginate
            ? $usersQuery->paginate($perPage)->through($formatUser)
            : $usersQuery->get()->map($formatUser);

        return response()->json([
            'status' => true,
            'message' => 'Subscribers revenue report retrieved successfully.',
            'data' => $result,
        ]);
    }
 
}
