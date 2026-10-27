<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Services\TelegramService;
use App\trait\image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TelegramAccountController extends Controller
{
    use image;

    public function __construct(
        protected TelegramService $telegramService
    ) {}

    /**
     * List all Telegram accounts (bots) for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = $user->telegramAccounts()
            ->latest()
            ->get()
            ->map(function (TelegramAccount $account) {
                $subInfo = $account->getSubscriptionInfo();

                return [
                    'id' => $account->id,
                    'bot_id' => $account->bot_id,
                    'bot_username' => $account->bot_username,
                    'bot_name' => $account->bot_name,
                    'status' => $account->status,
                    'msg_number' => $account->msg_number,
                    'start_date' => $account->start_date?->toDateString(),
                    'end_date' => $account->end_date?->toDateString(),
                    'ai_context' => $account->ai_context,
                    'ai_file' => $account->ai_file,
                    'android_link' => $account->android_link,
                    'ios_link' => $account->ios_link,
                    'website_url' => $account->website_url,
                    'subscription_status' => $subInfo['subscription_status'],
                    'available_msgs' => $subInfo['available_msgs'],
                    'created_at' => $account->created_at?->toDateTimeString(),
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Connect a new Telegram bot via its bot token.
     * Validates token with Telegram, saves account, and registers the webhook automatically.
     */
    public function connect(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'bot_token' => 'required|string',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
        ]);

        $botToken = trim($validated['bot_token']);

        // 1. Verify token with Telegram Bot API
        $botInfo = $this->telegramService->getMe($botToken);

        if (! $botInfo['success']) {
            return response()->json([
                'status' => false,
                'message' => $botInfo['message'] ?? 'Invalid Telegram Bot Token.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $botData = $botInfo['data'];
        $botId = $botData['id'];

        // 2. Check if this bot is already linked to another user
        $existing = TelegramAccount::where('bot_id', $botId)->first();
        if ($existing && $existing->user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'This Telegram bot is already linked to another account.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $verifyToken = (string) Str::uuid();

        $accountData = [
            'user_id' => $user->id,
            'bot_token' => $botToken,
            'bot_id' => $botId,
            'bot_username' => $botData['username'] ?: null,
            'bot_name' => $botData['first_name'] ?: null,
            'verify_token' => $verifyToken,
            'status' => 'active',
            'ai_context' => $validated['ai_context'] ?? null,
            'android_link' => $validated['android_link'] ?? null,
            'ios_link' => $validated['ios_link'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
        ];

        if ($request->hasFile('ai_file')) {
            $uploadedPath = $this->upload($request, 'ai_file', 'telegram/ai_files');
            if ($uploadedPath) {
                $accountData['ai_file'] = $uploadedPath;
            }
        } elseif (! empty($validated['ai_file']) && is_string($validated['ai_file'])) {
            $accountData['ai_file'] = $validated['ai_file'];
        }

        // Give new bots an initial trial quota (50 msgs, 30 days) so auto-reply works immediately
        if (! $existing) {
            $accountData['msg_number'] = 50;
            $accountData['start_date'] = now()->toDateString();
            $accountData['end_date'] = now()->addDays(30)->toDateString();
        }

        /** @var TelegramAccount $account */
        $account = TelegramAccount::updateOrCreate(
            ['bot_id' => $botId],
            $accountData
        );

        // 3. Register Webhook on Telegram (always with public HTTPS URL)
        $webhookUrl = $this->resolveWebhookUrl($account, $request);
        $webhookResult = $this->telegramService->setWebhook($botToken, $webhookUrl, $verifyToken);

        $subInfo = $account->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'message' => 'Telegram bot connected successfully.',
            'data' => [
                'id' => $account->id,
                'bot_id' => $account->bot_id,
                'bot_username' => $account->bot_username,
                'bot_name' => $account->bot_name,
                'status' => $account->status,
                'msg_number' => $account->msg_number,
                'start_date' => $account->start_date?->toDateString(),
                'end_date' => $account->end_date?->toDateString(),
                'ai_context' => $account->ai_context,
                'ai_file' => $account->ai_file,
                'android_link' => $account->android_link,
                'ios_link' => $account->ios_link,
                'website_url' => $account->website_url,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
                'webhook_url' => $webhookUrl,
                'webhook_registered' => $webhookResult['success'],
                'webhook_message' => $webhookResult['message'] ?? null,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Get details of a single Telegram account.
     */
    public function show(Request $request, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $telegramAccount);

        $subInfo = $telegramAccount->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $telegramAccount->id,
                'bot_id' => $telegramAccount->bot_id,
                'bot_username' => $telegramAccount->bot_username,
                'bot_name' => $telegramAccount->bot_name,
                'status' => $telegramAccount->status,
                'msg_number' => $telegramAccount->msg_number,
                'start_date' => $telegramAccount->start_date?->toDateString(),
                'end_date' => $telegramAccount->end_date?->toDateString(),
                'ai_context' => $telegramAccount->ai_context,
                'ai_file' => $telegramAccount->ai_file,
                'android_link' => $telegramAccount->android_link,
                'ios_link' => $telegramAccount->ios_link,
                'website_url' => $telegramAccount->website_url,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
                'created_at' => $telegramAccount->created_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Update Telegram account data (ai_context, ai_file, android_link, ios_link, website_url, status).
     */
    public function update(Request $request, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $telegramAccount);

        $validated = $request->validate([
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'status' => 'sometimes|in:active,disabled',
        ]);

        $updateFields = [];

        if ($request->has('ai_context')) {
            $updateFields['ai_context'] = $validated['ai_context'];
        }

        if ($request->hasFile('ai_file')) {
            $updatedPath = $this->update_image($request, $telegramAccount->ai_file, 'ai_file', 'telegram/ai_files');
            if ($updatedPath) {
                $updateFields['ai_file'] = $updatedPath;
            }
        } elseif ($request->exists('ai_file') && is_string($request->input('ai_file'))) {
            $updateFields['ai_file'] = $request->input('ai_file');
        }

        if ($request->has('android_link')) {
            $updateFields['android_link'] = $validated['android_link'];
        }

        if ($request->has('ios_link')) {
            $updateFields['ios_link'] = $validated['ios_link'];
        }

        if ($request->has('website_url')) {
            $updateFields['website_url'] = $validated['website_url'];
        }

        if ($request->has('status')) {
            $updateFields['status'] = $validated['status'];
        }

        if (! empty($updateFields)) {
            $telegramAccount->update($updateFields);
            $telegramAccount->refresh();
        }

        $subInfo = $telegramAccount->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'message' => 'Telegram account updated successfully.',
            'data' => [
                'id' => $telegramAccount->id,
                'bot_id' => $telegramAccount->bot_id,
                'bot_username' => $telegramAccount->bot_username,
                'bot_name' => $telegramAccount->bot_name,
                'status' => $telegramAccount->status,
                'msg_number' => $telegramAccount->msg_number,
                'start_date' => $telegramAccount->start_date?->toDateString(),
                'end_date' => $telegramAccount->end_date?->toDateString(),
                'ai_context' => $telegramAccount->ai_context,
                'ai_file' => $telegramAccount->ai_file,
                'android_link' => $telegramAccount->android_link,
                'ios_link' => $telegramAccount->ios_link,
                'website_url' => $telegramAccount->website_url,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
            ],
        ]);
    }

    /**
     * Disconnect / remove a Telegram bot account.
     */
    public function destroy(Request $request, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $telegramAccount);

        // Delete webhook from Telegram
        $this->telegramService->deleteWebhook($telegramAccount->bot_token);

        $telegramAccount->delete();

        return response()->json([
            'status' => true,
            'message' => 'Telegram bot disconnected successfully.',
        ]);
    }

    /**
     * Get webhook info directly from Telegram (registered URL, pending updates, last error).
     */
    public function webhookInfo(Request $request, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $telegramAccount);

        $info = $this->telegramService->getWebhookInfo($telegramAccount->bot_token);

        return response()->json([
            'status' => $info['success'],
            'data' => $info['data'] ?? [],
            'message' => $info['message'] ?? null,
        ]);
    }

    /**
     * Re-subscribe / re-register webhook with Telegram for this bot.
     */
    public function resubscribeWebhook(Request $request, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $telegramAccount);

        $verifyToken = $telegramAccount->verify_token ?: (string) Str::uuid();
        if (empty($telegramAccount->verify_token)) {
            $telegramAccount->update(['verify_token' => $verifyToken]);
        }

        $webhookUrl = $this->resolveWebhookUrl($telegramAccount, $request);
        $result = $this->telegramService->setWebhook($telegramAccount->bot_token, $webhookUrl, $verifyToken);

        return response()->json([
            'status' => $result['success'],
            'message' => $result['message'] ?? 'Webhook update attempted.',
            'webhook_url' => $webhookUrl,
        ]);
    }

    /**
     * Helper to resolve the correct public HTTPS webhook URL.
     */
    public function resolveWebhookUrl(TelegramAccount $account, Request $request): string
    {
        $host = $request->getHost();
        if ($host && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            return "https://{$host}/api/telegram-webhook/{$account->id}";
        }

        $appUrl = (string) config('app.url', 'https://bcknd.smartego.org');
        if (str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
            $appUrl = 'https://bcknd.smartego.org';
        }

        $appUrl = preg_replace('/^http:/i', 'https:', $appUrl);

        return rtrim($appUrl, '/')."/api/telegram-webhook/{$account->id}";
    }

    /**
     * Authorize that the TelegramAccount belongs to the current user.
     */
    protected function authorizeAccount(int $userId, TelegramAccount $telegramAccount): void
    {
        if ($telegramAccount->user_id !== $userId) {
            abort(Response::HTTP_FORBIDDEN, 'You do not own this Telegram account.');
        }
    }
}
