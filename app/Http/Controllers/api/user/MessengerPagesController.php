<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MessengerPagesController extends Controller
{
    private const GRAPH_API_BASE = 'https://graph.facebook.com';

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/user/messenger/pages
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List the authenticated user's Facebook Pages via Graph API.
     * Requires the user to be logged in via Facebook (has facebook_access_token).
     */
    public function pages(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->facebook_access_token)) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not linked to Facebook. Please login via Facebook first.',
            ], Response::HTTP_FORBIDDEN);
        }

        $graphVersion = config('services.meta.graph_version', 'v21.0');

        $response = Http::get(self::GRAPH_API_BASE."/{$graphVersion}/me/accounts", [
            'fields' => 'id,name,category,tasks',
            'access_token' => $user->facebook_access_token,
        ]);

        if (! $response->successful()) {
            Log::warning('MessengerPagesController::pages — Graph API failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch your Facebook Pages. Your access token may have expired.',
                'error' => $response->json('error.message'),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $rawPages = $response->json('data', []);

        // Mark pages that already have an active/pending Messenger account
        $existingPageIds = MessengerAccount::where('user_id', $user->id)
            ->pluck('status', 'page_id')
            ->toArray();

        $pages = collect($rawPages)->map(function (array $page) use ($existingPageIds) {
            $pageId = (string) $page['id'];

            return [
                'page_id' => $pageId,
                'page_name' => $page['name'] ?? null,
                'page_category' => $page['category'] ?? null,
                'already_linked' => isset($existingPageIds[$pageId]),
                'linked_status' => $existingPageIds[$pageId] ?? null,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'data' => $pages,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/user/messenger/orders
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Request a Messenger subscription for one of the user's Facebook Pages.
     * Creates a MessengerAccount (disabled) and an Order (pending) waiting for admin approval.
     *
     * @bodyParam page_id string required The Facebook Page ID. Example: 1050529558136350
     * @bodyParam package_id int required The package to subscribe to. Example: 1
     */
    public function requestSubscription(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->facebook_access_token)) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not linked to Facebook. Please login via Facebook first.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'page_id' => 'required|string|max:255',
            'package_id' => 'required|exists:packages,id',
        ]);

        $graphVersion = config('services.meta.graph_version', 'v21.0');

        // 1. Verify the page belongs to this user & get the page_access_token
        $accountsResponse = Http::get(self::GRAPH_API_BASE."/{$graphVersion}/me/accounts", [
            'fields' => 'id,name,category,access_token',
            'access_token' => $user->facebook_access_token,
        ]);

        if (! $accountsResponse->successful()) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to verify page ownership. Your access token may have expired.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $matchedPage = collect($accountsResponse->json('data', []))
            ->firstWhere('id', $validated['page_id']);

        if (! $matchedPage) {
            return response()->json([
                'status' => false,
                'message' => 'Page not found in your Facebook account. Make sure you are an admin of this page.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 2. Prevent duplicate active/pending subscriptions for the same page
        $existingAccount = MessengerAccount::where('page_id', $validated['page_id'])->first();

        if ($existingAccount) {
            $pendingOrder = Order::where('messenger_account_id', $existingAccount->id)
                ->where('status', 'pending')
                ->exists();

            if ($existingAccount->status === 'active' || $pendingOrder) {
                return response()->json([
                    'status' => false,
                    'message' => 'This page already has an active or pending Messenger subscription.',
                ], Response::HTTP_CONFLICT);
            }
        }

        // 3. Calculate price (same logic as admin OrderController::store)
        $package = Package::with(['discount', 'tax'])->findOrFail($validated['package_id']);

        $basePrice = (float) $package->price;
        $today = Carbon::today();

        $totalDiscount = 0.0;
        $discount = $package->discount;

        if ($discount) {
            $isWithinPeriod = true;

            if ($discount->from && $today->lt(Carbon::parse($discount->from)->startOfDay())) {
                $isWithinPeriod = false;
            }

            if ($discount->to && $today->gt(Carbon::parse($discount->to)->endOfDay())) {
                $isWithinPeriod = false;
            }

            if ($isWithinPeriod) {
                $totalDiscount = $discount->type === 'percentage'
                    ? ($basePrice * (float) $discount->amount) / 100
                    : (float) $discount->amount;

                $totalDiscount = min($totalDiscount, $basePrice);
            }
        }

        $priceAfterDiscount = max(0.0, $basePrice - $totalDiscount);
        $totalTax = 0.0;
        $tax = $package->tax;

        if ($tax) {
            $totalTax = $tax->type === 'percentage'
                ? ($priceAfterDiscount * (float) $tax->amount) / 100
                : (float) $tax->amount;
        }

        $finalPrice = $basePrice - $totalDiscount + $totalTax;
        $msgs = (int) $package->msg_number;

        // 4. Create MessengerAccount (disabled until approved)
        $messengerAccount = MessengerAccount::updateOrCreate(
            ['page_id' => $validated['page_id']],
            [
                'user_id' => $user->id,
                'page_name' => $matchedPage['name'] ?? null,
                'page_access_token' => $matchedPage['access_token'],
                'verify_token' => (string) Str::uuid(),
                'status' => 'disabled',
            ]
        );

        // 5. Create Order (pending — from/to will be set by admin on approval)
        $order = Order::create([
            'package_id' => $package->id,
            'user_id' => $user->id,
            'total_discount' => round($totalDiscount, 2),
            'total_tax' => round($totalTax, 2),
            'price' => round($basePrice, 2),
            'final_price' => round($finalPrice, 2),
            'msgs' => $msgs,
            'status' => 'pending',
            'channel' => 'messenger',
            'messenger_account_id' => $messengerAccount->id,
            'from' => null,
            'to' => null,
        ]);

        Log::info('Messenger subscription requested', [
            'user_id' => $user->id,
            'page_id' => $validated['page_id'],
            'order_id' => $order->id,
            'messenger_account_id' => $messengerAccount->id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Subscription request submitted. Awaiting admin approval.',
            'data' => [
                'order_id' => $order->id,
                'page_name' => $messengerAccount->page_name,
                'page_id' => $messengerAccount->page_id,
                'package' => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'msg_number' => $msgs,
                    'months' => $package->months,
                ],
                'price' => round($basePrice, 2),
                'total_discount' => round($totalDiscount, 2),
                'total_tax' => round($totalTax, 2),
                'final_price' => round($finalPrice, 2),
                'status' => 'pending',
            ],
        ], Response::HTTP_CREATED);
    }
}
