<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\TelegramService;
use App\trait\FiltersDailyTokens;
use App\trait\image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TelegramAccountController extends Controller
{
    use FiltersDailyTokens;
    use image;

    public function __construct(
        protected TelegramService $telegramService
    ) {}

    /**
     * List all Telegram accounts (bots) linked to a user (restaurant).
     */
    public function index(Request $request, User $user): JsonResponse
    {
        [$startDate, $endDate] = $this->resolveDateRange($request);

        $accounts = $user->telegramAccounts()
            ->select(
                'id',
                'user_id',
                'bot_id',
                'bot_username',
                'bot_name',
                'verify_token',
                'status',
                'msg_number',
                'start_date',
                'end_date',
                'ai_context',
                'ai_file',
                'android_link',
                'ios_link',
                'website_url',
                'created_at'
            )
            ->withSum(['dailyTokens as total_tokens' => function ($q) use ($startDate, $endDate) {
                $this->filterDailyTokensDate($q, $startDate, $endDate);
            }], 'tokens')
            ->latest('id')
            ->get();

        $accounts->each(function ($account) {
            $account->total_tokens = (int) ($account->total_tokens ?? 0);
            $subInfo = $account->getSubscriptionInfo();
            $account->subscription_status = $subInfo['subscription_status'];
            $account->available_msgs = $subInfo['available_msgs'];
        });

        return response()->json([
            'status' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Link a new Telegram Bot to the user (restaurant).
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'bot_token' => 'required|string',
            'status' => 'sometimes|in:active,disabled',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'msg_number' => 'sometimes|integer|min:0',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date|after_or_equal:start_date',
        ]);

        $botToken = trim($validated['bot_token']);

        // Verify token with Telegram Bot API
        $botInfo = $this->telegramService->getMe($botToken);

        if (! $botInfo['success']) {
            return response()->json([
                'status' => false,
                'message' => $botInfo['message'] ?? 'Invalid Telegram Bot Token.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $botData = $botInfo['data'];
        $botId = $botData['id'];

        $existing = TelegramAccount::where('bot_id', $botId)->first();
        if ($existing && $existing->user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'This Telegram bot is already linked to another user.',
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
            'status' => $validated['status'] ?? 'active',
            'ai_context' => $validated['ai_context'] ?? null,
            'android_link' => $validated['android_link'] ?? null,
            'ios_link' => $validated['ios_link'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
            'msg_number' => $validated['msg_number'] ?? 0,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ];

        if ($request->hasFile('ai_file')) {
            $uploadedPath = $this->upload($request, 'ai_file', 'telegram/ai_files');
            if ($uploadedPath) {
                $accountData['ai_file'] = $uploadedPath;
            }
        } elseif (! empty($validated['ai_file']) && is_string($validated['ai_file'])) {
            $accountData['ai_file'] = $validated['ai_file'];
        }

        /** @var TelegramAccount $account */
        $account = TelegramAccount::updateOrCreate(
            ['bot_id' => $botId],
            $accountData
        );

        $webhookUrl = url("/api/telegram-webhook/{$account->id}");
        $this->telegramService->setWebhook($botToken, $webhookUrl, $verifyToken);

        return response()->json([
            'status' => true,
            'message' => 'Telegram bot linked successfully.',
            'data' => $account,
        ], Response::HTTP_CREATED);
    }

    /**
     * View details of a single Telegram account.
     */
    public function show(User $user, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizeAccount($user, $telegramAccount);

        $subInfo = $telegramAccount->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $telegramAccount->id,
                'user_id' => $telegramAccount->user_id,
                'bot_id' => $telegramAccount->bot_id,
                'bot_username' => $telegramAccount->bot_username,
                'bot_name' => $telegramAccount->bot_name,
                'verify_token' => $telegramAccount->verify_token,
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
     * Update a Telegram account (admin can update all data including quota & dates).
     */
    public function update(Request $request, User $user, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizeAccount($user, $telegramAccount);

        $validated = $request->validate([
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'status' => 'sometimes|in:active,disabled',
            'msg_number' => 'sometimes|integer|min:0',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date|after_or_equal:start_date',
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

        if ($request->has('msg_number')) {
            $updateFields['msg_number'] = $validated['msg_number'];
        }

        if ($request->has('start_date')) {
            $updateFields['start_date'] = $validated['start_date'];
        }

        if ($request->has('end_date')) {
            $updateFields['end_date'] = $validated['end_date'];
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
                'user_id' => $telegramAccount->user_id,
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
     * Remove a Telegram bot from the user.
     */
    public function destroy(User $user, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizeAccount($user, $telegramAccount);

        $this->telegramService->deleteWebhook($telegramAccount->bot_token);
        $telegramAccount->delete();

        return response()->json([
            'status' => true,
            'message' => 'Telegram account removed successfully.',
        ]);
    }

    /**
     * Ensure the TelegramAccount belongs to the given User.
     */
    private function authorizeAccount(User $user, TelegramAccount $telegramAccount): void
    {
        if ($telegramAccount->user_id !== $user->id) {
            abort(Response::HTTP_FORBIDDEN, 'This Telegram account does not belong to the specified user.');
        }
    }
}
