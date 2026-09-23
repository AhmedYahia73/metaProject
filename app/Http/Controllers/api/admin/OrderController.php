<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    /**
     * Display a listing of orders (paginated).
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Number of orders per page (default: 15). Example: 15
     * @queryParam user_id integer Filter orders by user ID. Example: 1
     * @queryParam package_id integer Filter orders by package ID. Example: 2
     * @queryParam search string Search by order ID, customer name, or phone. Example: Ahmed
     * @queryParam paginate boolean Whether to paginate the results (default: true). Example: true
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'user_id' => 'sometimes|exists:users,id',
            'package_id' => 'sometimes|exists:packages,id',
            'search' => 'sometimes|string|max:255',
            'paginate' => 'sometimes|boolean',
            'status' => 'sometimes|in:pending,approved,rejected',
            'channel' => 'sometimes|in:whatsapp,messenger',
        ]);

        $query = Order::with(['package:id,name', 'user:id,name,phone'])->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('package_id')) {
            $query->where('package_id', $request->package_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

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

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $transform = function ($order) {
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
                'created_at' => $order->created_at,
            ];
        };

        if ($isPaginated) {
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
        }

        $orders = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Store a newly created order with automated calculations.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'from' => 'required|date',
            'package_id' => 'required_without:packag_id|nullable|exists:packages,id',
        ]);

        $packageId = $validated['package_id'] ?? $validated['packag_id'];
        $package = Package::with(['discount', 'tax'])->findOrFail($packageId);
        $user = User::findOrFail($validated['user_id']);

        $basePrice = (float) $package->price;
        $fromDate = Carbon::parse($validated['from']);

        // 1. Check and calculate discount
        $totalDiscount = 0.0;
        $discount = $package->discount;

        if ($discount) {
            $isWithinPeriod = true;

            if ($discount->from && $fromDate->lt(Carbon::parse($discount->from)->startOfDay())) {
                $isWithinPeriod = false;
            }

            if ($discount->to && $fromDate->gt(Carbon::parse($discount->to)->endOfDay())) {
                $isWithinPeriod = false;
            }

            if ($isWithinPeriod) {
                if ($discount->type === 'percentage') {
                    $totalDiscount = ($basePrice * (float) $discount->amount) / 100;
                } else {
                    $totalDiscount = (float) $discount->amount;
                }

                // Discount cannot exceed base price
                $totalDiscount = min($totalDiscount, $basePrice);
            }
        }

        // 2. Calculate tax on the price after discount
        $priceAfterDiscount = max(0.0, $basePrice - $totalDiscount);
        $totalTax = 0.0;
        $tax = $package->tax;

        if ($tax) {
            if ($tax->type === 'percentage') {
                $totalTax = ($priceAfterDiscount * (float) $tax->amount) / 100;
            } else {
                $totalTax = (float) $tax->amount;
            }
        }

        // 3. Final price = package price - total discount + total tax
        $finalPrice = $basePrice - $totalDiscount + $totalTax;

        // 4. End date 'to' = from + package months
        $months = max(1, (int) $package->months);
        $toDate = $fromDate->copy()->addMonths($months);

        // 5. Total messages = package months * msg_number
        $msgs = (int) $package->msg_number;

        // 6. Save order
        $order = Order::create([
            'package_id' => $package->id,
            'user_id' => $user->id,
            'total_discount' => round($totalDiscount, 2),
            'total_tax' => round($totalTax, 2),
            'price' => round($basePrice, 2),
            'final_price' => round($finalPrice, 2),
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'msgs' => $msgs,
        ]);

        // Increment user's message quota
        $user->increment('msg_number', $msgs);

        $order->load(['package:id,name', 'user:id,name,phone']);

        return response()->json([
            'status' => true,
            'message' => 'Order created successfully.',
            'data' => [
                'id' => $order->id,
                'package_id' => $order->package_id,
                'package_name' => $order->package?->name,
                'total_discount' => (float) $order->total_discount,
                'total_tax' => (float) $order->total_tax,
                'price' => (float) $order->price,
                'final_price' => (float) $order->final_price,
                'user_id' => $order->user_id,
                'user_name' => $order->user?->name,
                'user_phone' => $order->user?->phone,
                'msgs' => (int) $order->msgs,
                'from' => $order->from ? Carbon::parse($order->from)->toDateString() : null,
                'to' => $order->to ? Carbon::parse($order->to)->toDateString() : null,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Return lists of users (id, name) and packages (id, name[en]) for selects.
     */
    public function lists(): JsonResponse
    {
        $users = User::select('id', 'name')->latest()->get();

        $packages = Package::select('id', 'name')->latest()->get()->map(function ($package) {
            $nameEn = is_array($package->name)
                ? ($package->name['en'] ?? reset($package->name))
                : $package->name;

            return [
                'id' => $package->id,
                'name' => $nameEn,
            ];
        });

        return response()->json([
            'status' => true,
            'data' => [
                'users' => $users,
                'packages' => $packages,
            ],
        ]);
    }

    /**
     * Display a specific order.
     */
    public function show(Order $order): JsonResponse
    {
        $order->load(['package:id,name', 'user:id,name,phone']);

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $order->id,
                'package_id' => $order->package_id,
                'package_name' => $order->package?->name,
                'total_discount' => (float) $order->total_discount,
                'total_tax' => (float) $order->total_tax,
                'price' => (float) $order->price,
                'final_price' => (float) $order->final_price,
                'user_id' => $order->user_id,
                'user_name' => $order->user?->name,
                'user_phone' => $order->user?->phone,
                'msgs' => (int) $order->msgs,
                'from' => $order->from ? Carbon::parse($order->from)->toDateString() : null,
                'to' => $order->to ? Carbon::parse($order->to)->toDateString() : null,
                'status' => $order->status,
                'channel' => $order->channel,
                'created_at' => $order->created_at,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Order Status Actions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Approve a pending order.
     *
     * - Sets from = today, to = today + package.months, status = approved.
     * - For Messenger orders: activates the MessengerAccount and subscribes the
     *   page to the Meta App webhook.
     * - For WhatsApp orders: increments the user's msg_number quota.
     */
    public function approve(Order $order): JsonResponse
    {
        if (! $order->isPending()) {
            return response()->json([
                'status' => false,
                'message' => "Order #{$order->id} is already {$order->status} and cannot be approved.",
            ], Response::HTTP_CONFLICT);
        }

        $package = Package::findOrFail($order->package_id);
        $fromDate = Carbon::today();
        $months = max(1, (int) $package->months);
        $toDate = $fromDate->copy()->addMonths($months);

        $order->update([
            'status' => 'approved',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
        ]);

        $activationResult = [];

        if ($order->isMessenger()) {
            /** @var MessengerAccount|null $account */
            $account = $order->messengerAccount;

            if ($account) {
                $account->update(['status' => 'active']);

                // Subscribe the Facebook Page to receive messages via our webhook
                $graphVersion = config('services.meta.graph_version', 'v21.0');

                $subResponse = Http::post(
                    "https://graph.facebook.com/{$graphVersion}/{$account->page_id}/subscribed_apps",
                    [
                        'subscribed_fields' => 'messages,messaging_postbacks',
                        'access_token' => $account->page_access_token,
                    ]
                );

                $subscribed = $subResponse->successful() && ($subResponse->json('success') === true);

                Log::info('Order approve: Messenger page subscription', [
                    'order_id' => $order->id,
                    'page_id' => $account->page_id,
                    'subscribed' => $subscribed,
                    'response' => $subResponse->json(),
                ]);

                $activationResult = [
                    'messenger_page_id' => $account->page_id,
                    'messenger_page_name' => $account->page_name,
                    'messenger_subscribed' => $subscribed,
                    'webhook_url' => url('/api/messenger-webhook'),
                    'verify_token' => $account->verify_token,
                ];
            }
        } else {
            // WhatsApp: increment message quota

            $activationResult = [
                'whatsapp_msgs_added' => $order->msgs,
            ];
        }
        $order->user->increment('msg_number', $order->msgs);

        Log::info("Order #{$order->id} approved", [
            'channel' => $order->channel,
            'user_id' => $order->user_id,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
        ]);

        return response()->json([
            'status' => true,
            'message' => "Order #{$order->id} approved successfully.",
            'data' => [
                'order_id' => $order->id,
                'status' => 'approved',
                'channel' => $order->channel,
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
                'activation' => $activationResult,
            ],
        ]);
    }

    /**
     * Reject a pending order.
     *
     * - Sets status = rejected.
     * - For Messenger orders: keeps the MessengerAccount as disabled (audit trail).
     */
    public function reject(Request $request, Order $order): JsonResponse
    {
        if (! $order->isPending()) {
            return response()->json([
                'status' => false,
                'message' => "Order #{$order->id} is already {$order->status} and cannot be rejected.",
            ], Response::HTTP_CONFLICT);
        }

        $request->validate([
            'reason' => 'sometimes|nullable|string|max:500',
        ]);

        $order->update(['status' => 'rejected']);

        // Keep MessengerAccount as disabled — acts as audit trail
        // The user can submit a new request with a different package

        Log::info("Order #{$order->id} rejected", [
            'channel' => $order->channel,
            'user_id' => $order->user_id,
            'reason' => $request->input('reason'),
        ]);

        return response()->json([
            'status' => true,
            'message' => "Order #{$order->id} rejected.",
            'data' => [
                'order_id' => $order->id,
                'status' => 'rejected',
                'reason' => $request->input('reason'),
            ],
        ]);
    }
}
