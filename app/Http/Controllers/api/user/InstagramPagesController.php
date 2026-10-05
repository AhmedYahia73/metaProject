<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use App\trait\image;
use App\trait\paymob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InstagramPagesController extends Controller
{
    use image, paymob;

    private const GRAPH_API_BASE = 'https://graph.facebook.com';

    /**
     * List packages available for Instagram (type 'instagram' or 'all').
     */
    public function instagram_packages(Request $request): JsonResponse
    {
        $request->validate([
            'lang' => 'sometimes|in:ar,en',
        ]);
        $rawLang = $request->query('lang', $request->header('Accept-Language', 'ar'));
        $lang = str_starts_with(strtolower((string) $rawLang), 'en') ? 'en' : 'ar';

        $packages = Package::with(['discount', 'tax'])
            ->where(function ($query) {
                $query->where('type', 'all')
                    ->orWhere('type', 'instagram');
            })
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
                ];
            });

        return response()->json([
            'status' => true,
            'lang' => $lang,
            'instagram_packages' => $packages,
            'data' => $packages,
        ]);
    }

    /**
     * List user's linked Instagram Business accounts via Graph API with zero manual entry.
     * Extracts linked Instagram Business accounts from the user's Facebook pages.
     */
    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->facebook_access_token)) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not linked to Facebook/Instagram. Please login via Facebook/Instagram first.',
            ], Response::HTTP_FORBIDDEN);
        }

        $graphVersion = config('services.meta.graph_version', 'v21.0');

        try {
            $response = Http::withToken($user->facebook_access_token)
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    ],
                ])
                ->timeout(30)
                ->retry(2, 200, throw: false)
                ->get(self::GRAPH_API_BASE."/{$graphVersion}/me/accounts", [
                    'fields' => 'id,name,category,access_token,instagram_business_account{id,username,name,profile_picture_url}',
                    'access_token' => $user->facebook_access_token,
                ]);
        } catch (\Throwable $e) {
            Log::error('InstagramPagesController::accounts — Meta connection error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to connect to Meta servers. Please check server network or try again.',
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        if (! $response->successful()) {
            Log::warning('InstagramPagesController::accounts — Graph API failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch Instagram accounts from Meta. Your access token may have expired.',
                'error' => $response->json('error.message'),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $rawPages = $response->json('data', []);

        $existingAccounts = InstagramItem::where('user_id', $user->id)
            ->get()
            ->keyBy('instagram_id');

        $instagramAccounts = collect($rawPages)
            ->filter(fn (array $page) => ! empty($page['instagram_business_account']))
            ->map(function (array $page) use ($existingAccounts, $user) {
                $ig = $page['instagram_business_account'];
                $igId = (string) $ig['id'];
                /** @var InstagramItem|null $account */
                $account = $existingAccounts->get($igId);

                // Automatically update existing InstagramItem token
                if ($account && ! empty($page['access_token']) && $account->access_token !== $page['access_token']) {
                    $account->update(['access_token' => $page['access_token'], 'page_id' => (string) $page['id']]);
                }

                // Also update MessengerAccount token if exists for this page
                if (! empty($page['access_token'])) {
                    MessengerAccount::where('user_id', $user->id)
                        ->where('page_id', (string) $page['id'])
                        ->update(['page_access_token' => $page['access_token']]);
                }

                $subInfo = $account ? $account->getSubscriptionInfo() : [
                    'subscription_status' => false,
                    'available_msgs' => 0,
                ];

                return [
                    'instagram_id' => $igId,
                    'username' => $ig['username'] ?? null,
                    'name' => $ig['name'] ?? null,
                    'profile_picture_url' => $ig['profile_picture_url'] ?? null,
                    'connected_page_id' => (string) $page['id'],
                    'connected_page_name' => $page['name'] ?? null,
                    'already_linked' => $account !== null,
                    'linked_status' => $account?->status,
                    'subscription_status' => $subInfo['subscription_status'],
                    'available_msgs' => $subInfo['available_msgs'],
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => $instagramAccounts->isEmpty()
                ? 'No Instagram Business accounts found linked to your Facebook pages. Please ensure your Instagram Professional account is connected to a Facebook Page in Meta Business Suite.'
                : 'Instagram accounts retrieved successfully.',
            'data' => $instagramAccounts,
        ]);
    }

    /**
     * Request an Instagram subscription for an Instagram Business Account.
     * Automatically extracts access token from Graph API without manual entry.
     */
    public function requestSubscription(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->facebook_access_token)) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not linked to Facebook/Instagram. Please login via Facebook/Instagram first.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'instagram_id' => 'required|string|max:255',
            'package_id' => [
                'required',
                Rule::exists('packages', 'id')->where(function ($query) {
                    $query->whereIn('type', ['instagram', 'all']);
                }),
            ],
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
        ], [
            'package_id.exists' => 'The selected package is invalid or not available for Instagram.',
        ]);

        $graphVersion = config('services.meta.graph_version', 'v21.0');

        // Fetch user pages and linked Instagram accounts to retrieve page token and details automatically
        try {
            $accountsResponse = Http::withToken($user->facebook_access_token)
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    ],
                ])
                ->timeout(30)
                ->retry(2, 200, throw: false)
                ->get(self::GRAPH_API_BASE."/{$graphVersion}/me/accounts", [
                    'fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
                    'access_token' => $user->facebook_access_token,
                ]);
        } catch (\Throwable $e) {
            Log::error('InstagramPagesController::requestSubscription — Meta connection error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to verify Instagram account with Meta due to a connection issue. Please try again.',
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        if (! $accountsResponse->successful()) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to verify Instagram account with Meta.',
                'error' => $accountsResponse->json('error.message'),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $matchedPage = null;
        $matchedIg = null;

        foreach ($accountsResponse->json('data', []) as $page) {
            if (isset($page['instagram_business_account']) && (string) $page['instagram_business_account']['id'] === (string) $validated['instagram_id']) {
                $matchedPage = $page;
                $matchedIg = $page['instagram_business_account'];
                break;
            }
        }

        if (! $matchedPage || ! $matchedIg) {
            return response()->json([
                'status' => false,
                'message' => 'The selected Instagram account was not found under your linked Facebook pages.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $package = Package::with(['discount', 'tax'])->findOrFail($validated['package_id']);

        $uploadedPath = null;
        if ($request->hasFile('ai_file')) {
            $uploadedPath = $this->upload($request, 'ai_file', 'instagram/ai_files');
        } elseif ($request->has('ai_file') && is_string($request->input('ai_file'))) {
            $uploadedPath = $request->input('ai_file');
        }

        // Find or create InstagramItem
        $instagramItem = InstagramItem::where('instagram_id', $validated['instagram_id'])->first();

        $accountData = [
            'user_id' => $user->id,
            'instagram_id' => (string) $matchedIg['id'],
            'username' => $matchedIg['username'] ?? null,
            'name' => $matchedIg['name'] ?? null,
            'profile_picture_url' => $matchedIg['profile_picture_url'] ?? null,
            'page_id' => (string) $matchedPage['id'],
            'access_token' => $matchedPage['access_token'] ?? $user->facebook_access_token,
            'status' => 'disabled', // Active upon admin approval
            'android_link' => $validated['android_link'] ?? $instagramItem?->android_link,
            'ios_link' => $validated['ios_link'] ?? $instagramItem?->ios_link,
            'website_url' => $validated['website_url'] ?? $instagramItem?->website_url,
            'ai_context' => $validated['ai_context'] ?? $instagramItem?->ai_context,
            'ai_file' => $uploadedPath ?? $instagramItem?->ai_file,
        ];

        if ($instagramItem) {
            $instagramItem->update($accountData);
        } else {
            $accountData['verify_token'] = (string) Str::uuid();
            $instagramItem = InstagramItem::create($accountData);
        }

        // Calculate pricing
        $basePrice = (float) $package->price;
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        if ($package->discount) {
            $totalDiscount = $package->discount->type === 'percentage'
                ? $basePrice * ((float) $package->discount->value / 100)
                : min((float) $package->discount->value, $basePrice);
        }

        $priceAfterDiscount = $basePrice - $totalDiscount;

        if ($package->tax) {
            $totalTax = $package->tax->type === 'percentage'
                ? $priceAfterDiscount * ((float) $package->tax->value / 100)
                : (float) $package->tax->value;
        }

        $finalPrice = round($basePrice - $totalDiscount + $totalTax, 2);
        $msgs = (int) $package->msg_number;

        $order = Order::create([
            'package_id' => $package->id,
            'user_id' => $user->id,
            'instagram_item_id' => $instagramItem->id,
            'channel' => 'instagram',
            'status' => 'faild',
            'total_discount' => round($totalDiscount, 2),
            'total_tax' => round($totalTax, 2),
            'price' => round($basePrice, 2),
            'final_price' => $finalPrice,
            'msgs' => $msgs,
        ]);

        $paymobUrl = $this->getPaymobPaymentLink($order);

        return response()->json([
            'status' => true,
            'message' => 'Subscription request submitted successfully. Please complete payment.',
            'data' => [
                'order_id' => $order->id,
                'status' => $order->status,
                'channel' => 'instagram',
                'instagram_item_id' => $instagramItem->id,
                'instagram_username' => $instagramItem->username,
                'package_id' => $package->id,
                'final_price' => $finalPrice,
                'msgs' => $msgs,
                'payment_url' => $paymobUrl,
                'paymob_url' => $paymobUrl,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Get or update AI data for a specific Instagram item.
     */
    public function ai_data(Request $request): JsonResponse
    {
        $request->validate([
            'instagram_id' => 'required_without:instagram_item_id|string',
            'instagram_item_id' => 'required_without:instagram_id|integer',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'ai_context' => 'sometimes',
            'android_link' => 'sometimes',
            'ios_link' => 'sometimes',
            'website_url' => 'sometimes',
        ]);

        $query = InstagramItem::query();
        if ($request->filled('instagram_id')) {
            $query->where('instagram_id', $request->instagram_id);
        } else {
            $query->where('id', $request->instagram_item_id);
        }

        $data = $query->latest('id')->first();

        if (! $data) {
            return response()->json([
                'status' => false,
                'message' => 'Instagram account not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $updateFields = [];
        if ($request->has('ai_context')) {
            $updateFields['ai_context'] = $request->ai_context;
        }
        if ($request->hasFile('ai_file')) {
            $uploadedPath = $this->upload($request, 'ai_file', 'instagram/ai_files');
            if ($uploadedPath) {
                $updateFields['ai_file'] = $uploadedPath;
            }
        } elseif ($request->filled('ai_file') && is_string($request->ai_file)) {
            $updateFields['ai_file'] = $request->ai_file;
        }
        if ($request->has('android_link')) {
            $updateFields['android_link'] = $request->android_link;
        }
        if ($request->has('ios_link')) {
            $updateFields['ios_link'] = $request->ios_link;
        }
        if ($request->has('website_url')) {
            $updateFields['website_url'] = $request->website_url;
        }

        if (! empty($updateFields)) {
            $data->update($updateFields);
            $data->refresh();
        }

        return response()->json([
            'status' => true,
            'ai_context' => $data->ai_context,
            'ai_file' => $data->ai_file,
            'website_url' => $data->website_url,
            'data' => [
                'ai_context' => $data->ai_context,
                'ai_file' => $data->ai_file,
                'android_link' => $data->android_link,
                'ios_link' => $data->ios_link,
                'website_url' => $data->website_url,
            ],
        ]);
    }

    /**
     * List user's linked Instagram items in database.
     */
    public function items(Request $request): JsonResponse
    {
        $items = $request->user()->instagramItems()
            ->latest()
            ->get()
            ->map(function (InstagramItem $item) {
                return [
                    'id' => $item->id,
                    'instagram_id' => $item->instagram_id,
                    'username' => $item->username,
                    'name' => $item->name,
                    'profile_picture_url' => $item->profile_picture_url,
                    'status' => $item->status,
                    'subscription' => $item->getSubscriptionInfo(),
                    'ai_context' => $item->ai_context,
                    'ai_file' => $item->ai_file,
                    'website_url' => $item->website_url,
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $items,
        ]);
    }
}
