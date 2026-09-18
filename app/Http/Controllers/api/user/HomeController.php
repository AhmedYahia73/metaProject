<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContactUsRequest;
use App\Mail\ContactUsMail;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class HomeController extends Controller
{
    /**
     * User / Restaurant Dashboard summary.
     * Returns total messages, used messages, remaining messages, active package, and monthly stats.
     *
     * @queryParam from date Start date filter (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam to date End date filter (YYYY-MM-DD). Example: 2026-12-31
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date|after_or_equal:from',
        ]);

        $today = now()->toDateString();

        // 1. Build Orders query
        $orderQuery = Order::where('user_id', $request->user()->id);

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
        $periodTo = (clone $orderQuery)->max('to') ?? $request->to ?? $today;

        // 2. Build MsgSend query
        $msgSendQuery = MsgSend::where('user_id', $request->user()->id);

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
        $targetUser = User::find($request->user()->id);
        $overview = [
            'restaurant_name' => $targetUser?->restuarant_name,
            'phone' => $targetUser?->phone,
            'phone_status' => $targetUser?->phone_status,
        ];

        return response()->json([
            'status' => true,
            'message' => 'Admin dashboard data',
            'data' => [
                'active_order' => $totalAllocatedMsgs,
                'used' => $used,
                'remaining' => $remaining,
                'period' => [
                    'from' => $periodFrom,
                    'to' => $periodTo,
                ],
                'overview' => $overview,
            ],
        ]);
    }

    public function packages(Request $request): JsonResponse
    {
        $request->validate([
            'lang' => 'required|in:ar,en',
        ]);
        $rawLang = $request->query('lang', $request->header('Accept-Language', 'ar'));
        $lang = str_starts_with(strtolower((string) $rawLang), 'en') ? 'en' : 'ar';

        $packages = Package::with(['discount', 'tax'])
            ->latest()
            ->get()
            ->map(function (Package $package) use ($lang) {
                $names = is_array($package->name)
                    ? $package->name
                    : (json_decode((string) $package->name, true) ?: []);

                $localizedName = $names[$lang] ?? $names['en'] ?? $names['ar'] ?? (is_string($package->name) ? $package->name : '');

                return [
                    'id' => $package->id,
                    'name' => $localizedName,
                    'names' => $names,
                    'msg_number' => $package->msg_number,
                    'price' => $package->price,
                    'months' => $package->months,
                    'discount_id' => $package->discount_id,
                    'tax_id' => $package->tax_id,
                    'discount' => $package->discount,
                    'tax' => $package->tax,
                    'created_at' => $package->created_at,
                    'updated_at' => $package->updated_at,
                ];
            });

        return response()->json([
            'status' => true,
            'lang' => $lang,
            'data' => $packages,
        ]);
    }

    /**
     * Submit contact us form and send email to admin.
     * Rate limited to 2 submissions per 5 minutes.
     */
    public function contactUs(ContactUsRequest $request): JsonResponse
    {
        $recipient = config('mail.my_email', env('My_Email', 'ahmedahmadahmid73@gmail.com'));

        try {
            Mail::to($recipient)->send(new ContactUsMail($request->validated()));

            return response()->json([
                'status' => true,
                'message' => 'Your message has been sent successfully.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send Contact Us email: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send message. Please try again later.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
