<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\WhatsItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriperController extends Controller
{
    /**
     * Display a listing of subscribers with their remaining messages and next expected renewal.
     */
    public function subscripers(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'user_id' => 'sometimes|exists:users,id',
            'search' => 'sometimes|string|max:255',
            'channel' => 'sometimes|in:whatsapp,messenger,instagram',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = User::query()
            ->with(['messengerAccounts', 'whatsItems', 'instagramItems'])
            ->where(function ($query) use ($request) {
                if ($request->filled('user_id')) {
                    $query->where('id', $request->input('user_id'));
                }
                if ($request->filled('search')) {
                    $search = $request->input('search');
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }
            });

        if ($request->filled('channel')) {
            $channel = $request->input('channel');
            if ($channel === 'messenger') {
                $query->where(function ($q) {
                    $q->whereHas('messengerAccounts', function ($mq) {
                        $mq->whereNotNull('start_date')->orWhereNotNull('end_date');
                    })->orWhereHas('orders', function ($oq) {
                        $oq->where('channel', 'messenger')
                            ->whereNotIn('status', ['faild', 'failed', 'rejected']);
                    });
                });
            } elseif ($channel === 'whatsapp') {
                $query->where(function ($q) {
                    $q->whereHas('whatsItems', function ($wq) {
                        $wq->whereNotNull('start_date')->orWhereNotNull('end_date');
                    })->orWhereHas('orders', function ($oq) {
                        $oq->where('channel', 'whatsapp')
                            ->whereNotIn('status', ['faild', 'failed', 'rejected']);
                    });
                });
            } elseif ($channel === 'instagram') {
                $query->where(function ($q) {
                    $q->whereHas('instagramItems', function ($iq) {
                        $iq->whereNotNull('start_date')->orWhereNotNull('end_date');
                    })->orWhereHas('orders', function ($oq) {
                        $oq->where('channel', 'instagram')
                            ->whereNotIn('status', ['faild', 'failed', 'rejected']);
                    });
                });
            }
        } else {
            $query->where(function ($q) {
                $q->whereHas('messengerAccounts', function ($mq) {
                    $mq->whereNotNull('start_date')->orWhereNotNull('end_date');
                })->orWhereHas('whatsItems', function ($wq) {
                    $wq->whereNotNull('start_date')->orWhereNotNull('end_date');
                })->orWhereHas('instagramItems', function ($iq) {
                    $iq->whereNotNull('start_date')->orWhereNotNull('end_date');
                })->orWhereHas('orders', function ($oq) {
                    $oq->whereNotIn('status', ['faild', 'failed', 'rejected'])
                        ->whereNotNull('to');
                });
            });
        }

        $transform = function (User $user): array {
         
            $availableMsgs = (int) (
                $user->messengerAccounts->sum('msg_number') +
                $user->whatsItems->sum('msg_number') +
                $user->instagramItems->sum('msg_number')
            );
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'available_msgs' => $availableMsgs, 
            ];
        };

        $isPaginated = $request->boolean('paginate', true);
        $perPage = (int) $request->input('per_page', 10);

        if ($isPaginated) {
            $paginator = $query->paginate($perPage);
            $paginator->through($transform);

            return response()->json([
                'status' => true,
                'data' => $paginator,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ]);
        }

        $users = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'data' => $users,
        ]);
    }

    /**
     * Display detailed subscription information for a single subscriber.
     */
    public function subscriper(Request $request, int|string|null $id = null): JsonResponse
    {
        $subscriberId = $id ?: $request->input('user_id', $request->input('id'));
        if (! $subscriberId) {
            return response()->json([
                'status' => false,
                'message' => 'Subscriber ID is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = User::with([
            'messengerAccounts',
            'whatsItems',
            'instagramItems',
            'orders.package.discount',
            'orders.package.tax',
        ])->find($subscriberId);

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Subscriber not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $availableMsgs = (int) (
            $user->messengerAccounts->sum('msg_number') +
            $user->whatsItems->sum('msg_number') +
            $user->instagramItems->sum('msg_number')
        );

        $renewalInfo = $this->getNextRenewalInfo($user);

        $messengerAccounts = $user->messengerAccounts->map(function (MessengerAccount $account): array {
            $latestOrder = $account->orders()
                ->whereNotIn('status', ['faild', 'failed', 'rejected'])
                ->whereNotNull('to')
                ->latest('id')
                ->first();

            $from = $account->start_date?->toDateString()
                ?: ($latestOrder?->from ? Carbon::parse($latestOrder->from)->toDateString() : null);

            $to = $account->end_date?->toDateString()
                ?: ($latestOrder?->to ? Carbon::parse($latestOrder->to)->toDateString() : null);

            $isSubscribed = $account->hasActiveSubscription();

            return [
                'id' => $account->id,
                'name' => $account->page_name,
                'page_name' => $account->page_name,
                'page_id' => $account->page_id,
                'available_msgs' => max(0, (int) $account->msg_number),
                'from' => $from,
                'to' => $to,
                'start_date' => $from,
                'end_date' => $to,
                'is_subscripe' => $isSubscribed,
                'renewal_date' => $to,
                'status' => $account->status,
            ];
        });

        $whatsItems = $user->whatsItems->map(function (WhatsItem $item): array {
            $latestOrder = $item->orders()
                ->whereNotIn('status', ['faild', 'failed', 'rejected'])
                ->whereNotNull('to')
                ->latest('id')
                ->first();

            $from = $item->start_date?->toDateString()
                ?: ($latestOrder?->from ? Carbon::parse($latestOrder->from)->toDateString() : null);

            $to = $item->end_date?->toDateString()
                ?: ($latestOrder?->to ? Carbon::parse($latestOrder->to)->toDateString() : null);

            $isSubscribed = $item->hasActiveSubscription();

            return [
                'id' => $item->id,
                'name' => $item->phone,
                'phone' => $item->phone,
                'available_msgs' => max(0, (int) $item->msg_number),
                'from' => $from,
                'to' => $to, 
                'is_subscripe' => $isSubscribed,
                'renewal_date' => $to,
                'phone_status' => $item->phone_status,
                'status' => $item->phone_status,
            ];
        });

        $instagramItems = $user->instagramItems->map(function (InstagramItem $item): array {
            $latestOrder = $item->orders()
                ->whereNotIn('status', ['faild', 'failed', 'rejected'])
                ->whereNotNull('to')
                ->latest('id')
                ->first();

            $from = $item->start_date?->toDateString()
                ?: ($latestOrder?->from ? Carbon::parse($latestOrder->from)->toDateString() : null);

            $to = $item->end_date?->toDateString()
                ?: ($latestOrder?->to ? Carbon::parse($latestOrder->to)->toDateString() : null);

            $isSubscribed = $item->hasActiveSubscription();

            return [
                'id' => $item->id,
                'name' => $item->name ?: $item->username,
                'username' => $item->username,
                'available_msgs' => max(0, (int) $item->msg_number),
                'from' => $from,
                'to' => $to,
                'start_date' => $from,
                'end_date' => $to,
                'is_subscripe' => $isSubscribed,
                'renewal_date' => $to,
                'status' => $item->status,
            ];
        });

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'available_msgs' => $availableMsgs,
                'messenger_accounts_count' => $user->messengerAccounts->count(),
                'whatsapp_items_count' => $user->whatsItems->count(),
                'instagram_items_count' => $user->instagramItems->count(),
                'renewal_date' => $renewalInfo['renewal_date'],
                'next_order_price' => $renewalInfo['expected_amount'],
                'next_order' => $renewalInfo['next_order'],
                'messengerAccounts' => $messengerAccounts,
                'whatsItems' => $whatsItems,
                'instagramItems' => $instagramItems,
            ],
        ]);
    }

    /**
     * Determine the first order that will need renewal and calculate its expected cost.
     *
     * @return array{
     *     renewal_date: ?string,
     *     expected_amount: float,
     *     next_order: ?array<string, mixed>
     * }
     */
    public function getNextRenewalInfo(User $user): array
    {
        $orders = $user->orders()
            ->with(['package.discount', 'package.tax'])
            ->whereNotNull('to')
            ->whereNotIn('status', ['faild', 'failed', 'rejected'])
            ->orderBy('to', 'asc')
            ->get();

        if ($orders->isEmpty()) {
            // Check if any accounts have end_date if no order records exist
            $fallbackTo = null;
            $today = Carbon::today();
            $accountEndDates = [];

            foreach ($user->messengerAccounts as $acc) {
                if (! empty($acc->end_date)) {
                    $accountEndDates[] = Carbon::parse($acc->end_date);
                }
            }
            foreach ($user->whatsItems as $wItem) {
                if (! empty($wItem->end_date)) {
                    $accountEndDates[] = Carbon::parse($wItem->end_date);
                }
            }
            foreach ($user->instagramItems as $iItem) {
                if (! empty($iItem->end_date)) {
                    $accountEndDates[] = Carbon::parse($iItem->end_date);
                }
            }

            if (! empty($accountEndDates)) {
                $futureDates = array_filter($accountEndDates, fn ($d) => $d->gte($today));
                if (! empty($futureDates)) {
                    usort($futureDates, fn ($a, $b) => $a->timestamp <=> $b->timestamp);
                    $fallbackTo = reset($futureDates);
                } else {
                    usort($accountEndDates, fn ($a, $b) => $b->timestamp <=> $a->timestamp);
                    $fallbackTo = reset($accountEndDates);
                }
            }

            return [
                'renewal_date' => $fallbackTo?->toDateString(),
                'expected_amount' => 0.0,
                'next_order' => null,
            ];
        }

        // Group orders by item/channel to find the latest order for each active subscription stream
        $latestOrdersByItem = [];
        foreach ($orders as $order) {
            $key = null;
            if ($order->messenger_account_id) {
                $key = 'messenger_'.$order->messenger_account_id;
            } elseif ($order->whats_item_id) {
                $key = 'whatsapp_'.$order->whats_item_id;
            } elseif ($order->instagram_item_id) {
                $key = 'instagram_'.$order->instagram_item_id;
            } elseif ($order->channel) {
                $key = 'channel_'.$order->channel;
            } else {
                $key = 'package_'.$order->package_id;
            }

            if (! isset($latestOrdersByItem[$key]) || Carbon::parse($order->to)->gt(Carbon::parse($latestOrdersByItem[$key]->to))) {
                $latestOrdersByItem[$key] = $order;
            }
        }

        $today = Carbon::today();

        // 1. First priority: Latest orders that expire in the future or today (to >= today)
        $upcomingOrders = array_filter($latestOrdersByItem, function (Order $order) use ($today) {
            return Carbon::parse($order->to)->startOfDay()->gte($today);
        });

        if (! empty($upcomingOrders)) {
            // Sort ascending: earliest upcoming expiration date
            usort($upcomingOrders, function (Order $a, Order $b) {
                return Carbon::parse($a->to)->timestamp <=> Carbon::parse($b->to)->timestamp;
            });
            $selectedOrder = reset($upcomingOrders);
        } else {
            // 2. If all orders are in the past, pick the most recent one (max 'to')
            usort($latestOrdersByItem, function (Order $a, Order $b) {
                return Carbon::parse($b->to)->timestamp <=> Carbon::parse($a->to)->timestamp;
            });
            $selectedOrder = reset($latestOrdersByItem);
        }

        if (! $selectedOrder) {
            return [
                'renewal_date' => null,
                'expected_amount' => 0.0,
                'next_order' => null,
            ];
        }

        $renewalDate = Carbon::parse($selectedOrder->to);
        $package = $selectedOrder->package ?: Package::with(['discount', 'tax'])->find($selectedOrder->package_id);

        $pricing = $package
            ? $this->calculatePackagePricingOnDate($package, $renewalDate)
            : [
                'price' => (float) $selectedOrder->price,
                'total_discount' => (float) $selectedOrder->total_discount,
                'total_tax' => (float) $selectedOrder->total_tax,
                'final_price' => (float) $selectedOrder->final_price,
            ];

        return [
            'renewal_date' => $renewalDate->toDateString(),
            'expected_amount' => $pricing['final_price'],
            'next_order' => [
                'order_id' => $selectedOrder->id,
                'package_id' => $package?->id ?? $selectedOrder->package_id,
                'package_name' => $package?->name,
                'channel' => $selectedOrder->channel,
                'renewal_date' => $renewalDate->toDateString(),
                'price' => $pricing['price'],
                'total_discount' => $pricing['total_discount'],
                'total_tax' => $pricing['total_tax'],
                'final_price' => $pricing['final_price'],
            ],
        ];
    }

    /**
     * Calculate base price, discount, tax, and final price for a package on a specific target date.
     *
     * @return array{price: float, total_discount: float, total_tax: float, final_price: float}
     */
    public function calculatePackagePricingOnDate(Package $package, ?Carbon $targetDate = null): array
    {
        $package->loadMissing(['discount', 'tax']);

        $basePrice = (float) $package->price;
        $totalDiscount = 0.0;
        $totalTax = 0.0;
        $checkDate = $targetDate ? Carbon::parse($targetDate) : Carbon::today();

        $discount = $package->discount;
        if ($discount) {
            $discountAmount = (float) ($discount->amount ?? $discount->value ?? 0);
            $isWithinPeriod = true;

            if (! empty($discount->from)) {
                $fromDate = Carbon::parse($discount->from)->startOfDay();
                if ($checkDate->startOfDay()->lt($fromDate)) {
                    $isWithinPeriod = false;
                }
            }

            if (! empty($discount->to)) {
                $toDate = Carbon::parse($discount->to)->endOfDay();
                if ($checkDate->endOfDay()->gt($toDate)) {
                    $isWithinPeriod = false;
                }
            }

            if ($isWithinPeriod && $discountAmount > 0) {
                $isPercentage = in_array(strtolower((string) $discount->type), ['percentage', 'percent', '%'], true);
                $totalDiscount = $isPercentage
                    ? ($basePrice * $discountAmount) / 100
                    : $discountAmount;

                $totalDiscount = min($totalDiscount, $basePrice);
            }
        }

        $priceAfterDiscount = max(0.0, $basePrice - $totalDiscount);

        $tax = $package->tax;
        if ($tax) {
            $taxAmount = (float) ($tax->amount ?? $tax->value ?? 0);
            if ($taxAmount > 0) {
                $isTaxPercentage = in_array(strtolower((string) $tax->type), ['percentage', 'percent', '%'], true);
                $totalTax = $isTaxPercentage
                    ? ($priceAfterDiscount * $taxAmount) / 100
                    : $taxAmount;
            }
        }

        $finalPrice = max(0.0, $basePrice - $totalDiscount + $totalTax);

        return [
            'price' => round($basePrice, 2),
            'total_discount' => round($totalDiscount, 2),
            'total_tax' => round($totalTax, 2),
            'final_price' => round($finalPrice, 2),
        ];
    }
}
