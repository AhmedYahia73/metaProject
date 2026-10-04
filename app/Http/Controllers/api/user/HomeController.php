<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContactUsRequest;
use App\Mail\ContactUsMail;
use App\Models\Chat;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class HomeController extends Controller
{
    private const GRAPH_API_BASE = 'https://graph.facebook.com';

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

        if ($request->filled('messenger_account_id')) {
            $msgSendQuery->where('messenger_account_id', $request->messenger_account_id);
        }

        if ($request->filled('whats_item_id')) {
            $msgSendQuery->where('whats_item_id', $request->whats_item_id);
        }

        if ($request->filled('channel')) {
            $msgSendQuery->where('channel', $request->channel);
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

        $face_packages = Package::with(['discount', 'tax'])
            ->where(function ($query) {
                $query->where('type', 'all')
                    ->orWhere('type', 'face');
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
                    'created_at' => $package->created_at,
                    'updated_at' => $package->updated_at,
                ];
            });
        $whats_packages = Package::with(['discount', 'tax'])
            ->where(function ($query) {
                $query->where('type', 'all')
                    ->orWhere('type', 'whats');
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
                    'created_at' => $package->created_at,
                    'updated_at' => $package->updated_at,
                ];
            });

        return response()->json([
            'status' => true,
            'lang' => $lang,
            'face_packages' => $face_packages,
            'whats_packages' => $whats_packages,
        ]);
    }

    /**
     * Submit contact us form and send email to admin.
     * Rate limited to 2 submissions per 5 minutes.
     */
    public function contactUs(ContactUsRequest $request): JsonResponse
    {
        $recipient = config('mail.my_email')
            ?: env('My_Email')
            ?: env('MY_EMAIL')
            ?: env('MAIL_TO')
            ?: env('EMAIL_TO')
            ?: config('mail.from.address')
            ?: 'ahmedahmadahmid73@gmail.com';

        $activeMailer = config('mail.default');
        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name');

        Log::channel('stack')->info('[CONTACT_US] 🚀 Preparing to send Contact Us email', [
            'active_mailer' => $activeMailer,
            'recipient' => $recipient,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'smtp_host' => config('mail.mailers.smtp.host'),
            'smtp_port' => config('mail.mailers.smtp.port'),
            'smtp_encryption' => config('mail.mailers.smtp.encryption'),
            'smtp_username' => config('mail.mailers.smtp.username'),
            'smtp_verify_peer' => config('mail.mailers.smtp.verify_peer'),
            'form_data' => [
                'name' => trim(($request->f_name ?? '').' '.($request->l_name ?? '')),
                'email' => $request->email,
                'phone' => $request->phone,
            ],
        ]);

        if ($activeMailer === 'log') {
            Log::channel('stack')->warning('[CONTACT_US] ⚠️ MAIL_MAILER is set to "log"! The email was NOT sent to the SMTP server. It was written to storage/logs/laravel.log. Run "php artisan config:clear" on your server if you updated .env to smtp.');
        } elseif ($activeMailer === 'array') {
            Log::channel('stack')->warning('[CONTACT_US] ⚠️ MAIL_MAILER is set to "array"! The email was only captured in memory.');
        }

        try {
            /** @var SentMessage|null $sentMessage */
            $sentMessage = Mail::to($recipient)->send(new ContactUsMail($request->validated()));

            $debugOutput = $sentMessage?->getDebug();
            $messageId = $sentMessage?->getMessageId();

            Log::channel('stack')->info('[CONTACT_US] ✅ Mail::send() executed successfully', [
                'active_mailer' => $activeMailer,
                'recipient' => $recipient,
                'from_address' => $fromAddress,
                'message_id' => $messageId,
                'has_sent_message' => $sentMessage !== null,
                'smtp_debug' => $debugOutput,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Your message has been sent successfully.',
                'diagnostic' => [
                    'mailer' => $activeMailer,
                    'recipient' => $recipient,
                    'from' => $fromAddress,
                    'message_id' => $messageId,
                    'is_smtp' => $activeMailer === 'smtp',
                    'note' => $activeMailer === 'log'
                        ? 'MAIL_MAILER is "log". Email was saved to storage/logs/laravel.log rather than sent to SMTP. Run "php artisan config:clear".'
                        : 'Email was handed off to the configured mailer.',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::channel('stack')->error('[CONTACT_US] ❌ Mail sending failed with exception: '.$e->getMessage(), [
                'exception_class' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile().':'.$e->getLine(),
                'mailer' => $activeMailer,
                'recipient' => $recipient,
                'from_address' => $fromAddress,
                'smtp_host' => config('mail.mailers.smtp.host'),
                'smtp_port' => config('mail.mailers.smtp.port'),
                'smtp_username' => config('mail.mailers.smtp.username'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send message. Please try again later.',
                'error' => $e->getMessage(),
                'diagnostic' => [
                    'mailer' => $activeMailer,
                    'recipient' => $recipient,
                    'exception' => get_class($e),
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all connected communication channels/chats for the authenticated user.
     * Returns Facebook Pages, Instagram Business Accounts, and WhatsApp Numbers with profile pictures and subscription status.
     */
    public function all_chats(Request $request): JsonResponse
    {
        $user = $request->user();
        $graphVersion = config('services.meta.graph_version', 'v21.0');

        // Pre-aggregate unread message counts for each channel/page (Chat is_read = false)
        $messengerUnreadCounts = Chat::where('user_id', $user->id)
            ->whereNotNull('messenger_account_id')
            ->where('is_read', false)
            ->groupBy('messenger_account_id')
            ->selectRaw('messenger_account_id, count(*) as count')
            ->pluck('count', 'messenger_account_id');

        $instagramUnreadCounts = Chat::where('user_id', $user->id)
            ->whereNotNull('instagram_item_id')
            ->where('is_read', false)
            ->groupBy('instagram_item_id')
            ->selectRaw('instagram_item_id, count(*) as count')
            ->pluck('count', 'instagram_item_id');

        $whatsUnreadCounts = Chat::where('user_id', $user->id)
            ->whereNotNull('whats_item_id')
            ->where('is_read', false)
            ->groupBy('whats_item_id')
            ->selectRaw('whats_item_id, count(*) as count')
            ->pluck('count', 'whats_item_id');

        $messengerPages = collect();
        $instagramPages = collect();
        $facebookConnected = ! empty($user->facebook_access_token);

        if ($facebookConnected) {
            try {
                // Fetch Facebook Pages and linked Instagram accounts in a single optimized Meta Graph API request
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
                        'fields' => 'id,name,category,tasks,access_token,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
                        'access_token' => $user->facebook_access_token,
                    ]);

                if ($response->successful()) {
                    $rawPages = $response->json('data', []);

                    // Map existing registered accounts in database to retrieve subscription info
                    $existingMessengerAccounts = MessengerAccount::where('user_id', $user->id)
                        ->get()
                        ->keyBy('page_id');

                    $existingInstagramAccounts = InstagramItem::where('user_id', $user->id)
                        ->get()
                        ->keyBy('instagram_id');

                    // Map Facebook Pages with profile pictures and auto-update tokens
                    $messengerPages = collect($rawPages)->map(function (array $page) use ($existingMessengerAccounts, $messengerUnreadCounts) {
                        $pageId = (string) $page['id'];
                        /** @var MessengerAccount|null $account */
                        $account = $existingMessengerAccounts->get($pageId);

                        if ($account && ! empty($page['access_token']) && $account->page_access_token !== $page['access_token']) {
                            $account->update(['page_access_token' => $page['access_token']]);
                        }

                        if($account){
                            $subInfo = $account->getSubscriptionInfo();
                        }
                        else{
                            return null;
                        }

                        $pagePicture = $page['picture']['data']['url']
                            ?? "https://graph.facebook.com/{$pageId}/picture?type=large";

                        $unreadCount = $account ? (int) ($messengerUnreadCounts[$account->id] ?? 0) : 0;

                        return [
                            'page_id' => $pageId,
                            'page_name' => $page['name'] ?? null,
                            'page_category' => $page['category'] ?? null,
                            'profile_picture_url' => $pagePicture,
                            'subscription_status' => $subInfo['subscription_status'],
                            'available_msgs' => $subInfo['available_msgs'],
                            'unread_count' => $unreadCount,
                        ];
                    })->values();

                    // Map Instagram Business Accounts with profile pictures and auto-update tokens
                    $instagramPages = collect($rawPages)
                        ->filter(fn (array $page) => ! empty($page['instagram_business_account']))
                        ->map(function (array $page) use ($existingInstagramAccounts, $instagramUnreadCounts) {
                            $ig = $page['instagram_business_account'];
                            $igId = (string) $ig['id'];
                            /** @var InstagramItem|null $account */
                            $account = $existingInstagramAccounts->get($igId);

                            $pageAccessToken = $page['access_token'] ?? null;
                            if ($account && ! empty($pageAccessToken) && $account->access_token !== $pageAccessToken) {
                                $account->update(['access_token' => $pageAccessToken, 'page_id' => (string) $page['id']]);
                            }


                            if($account){
                                $subInfo = $account->getSubscriptionInfo();
                            }
                            else{
                                return null;
                            }

                            $unreadCount = $account ? (int) ($instagramUnreadCounts[$account->id] ?? 0) : 0;

                            return [
                                'instagram_id' => $igId,
                                'username' => $ig['username'] ?? null,
                                'name' => $ig['name'] ?? null,
                                'profile_picture_url' => $ig['profile_picture_url'] ?? null,
                                'connected_page_id' => (string) $page['id'],
                                'connected_page_name' => $page['name'] ?? null,
                                'subscription_status' => $subInfo['subscription_status'],
                                'available_msgs' => $subInfo['available_msgs'],
                                'unread_count' => $unreadCount,
                            ];
                        })->values();
                } else {
                    Log::warning('HomeController::all_chats — Meta Graph API call failed', [
                        'user_id' => $user->id,
                        'status' => $response->status(),
                        'body' => $response->json(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('HomeController::all_chats — Meta connection error', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If Meta call wasn't made or returned empty (e.g. token expired, Meta down, or not linked),
        // fallback to accounts saved locally in database so existing chats remain accessible
        if ($messengerPages->isEmpty()) {
            $messengerPages = MessengerAccount::where('user_id', $user->id)
                ->get()
                ->map(function (MessengerAccount $account) use ($messengerUnreadCounts) {
                    $subInfo = $account->getSubscriptionInfo();

                    return [
                        'page_id' => $account->page_id,
                        'page_name' => $account->page_name,
                        'page_category' => null,
                        'profile_picture_url' => "https://graph.facebook.com/{$account->page_id}/picture?type=large",
                        'subscription_status' => $subInfo['subscription_status'],
                        'available_msgs' => $subInfo['available_msgs'],
                        'unread_count' => (int) ($messengerUnreadCounts[$account->id] ?? 0),
                    ];
                });
        }

        if ($instagramPages->isEmpty()) {
            $instagramPages = InstagramItem::where('user_id', $user->id)
                ->get()
                ->map(function (InstagramItem $account) use ($instagramUnreadCounts) {
                    $subInfo = $account->getSubscriptionInfo();

                    return [
                        'instagram_id' => $account->instagram_id,
                        'username' => $account->username,
                        'name' => $account->name,
                        'profile_picture_url' => $account->profile_picture_url,
                        'connected_page_id' => $account->page_id,
                        'connected_page_name' => null,
                        'subscription_status' => $subInfo['subscription_status'],
                        'available_msgs' => $subInfo['available_msgs'],
                        'unread_count' => (int) ($instagramUnreadCounts[$account->id] ?? 0),
                    ];
                })
                ->values();
        }

        // Fetch WhatsApp numbers with profile pictures and active subscription information
        $whatsAccounts = $user->whatsItems()->latest()->get()
            ->map(function (WhatsItem $item) use ($whatsUnreadCounts) {
                $subInfo = $item->getSubscriptionInfo();
                if($account){
                    $subInfo = $account->getSubscriptionInfo();
                }
                else{
                    return null;
                }
                return [
                    'id' => $item->id,
                    'phone' => $item->phone,
                    'phone_number_id' => $item->phone_number_id,
                    'phone_status' => $item->phone_status,
                    'msg_number' => $item->msg_number,
                    'profile_picture_url' => $item->getProfilePictureUrl(),
                    'subscription_status' => $subInfo['subscription_status'],
                    'available_msgs' => $subInfo['available_msgs'],
                    'unread_count' => (int) ($whatsUnreadCounts[$item->id] ?? 0),
                ];
            })
            ->values();

        $totalUnreadCount = (int) $messengerPages->sum('unread_count')
            + (int) $instagramPages->sum('unread_count')
            + (int) $whatsAccounts->sum('unread_count');

        return response()->json([
            'status' => true,
            'facebook_connected' => $facebookConnected,
            'total_unread_count' => $totalUnreadCount,
            'messenger_pages' => $messengerPages->filter()->values(),
            'instagram_pages' => $instagramPages->filter()->values(),
            'whats_accounts' => $whatsAccounts->filter()->values(),
        ]);
    }
}
