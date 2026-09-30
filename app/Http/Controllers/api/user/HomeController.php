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
use Illuminate\Mail\SentMessage;
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
}
