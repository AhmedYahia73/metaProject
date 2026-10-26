<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\MsgSend;
use App\Models\TelegramAccount;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramService $telegramService
    ) {}

    /**
     * Handle incoming Telegram webhook updates for a specific bot account.
     * Route: POST /api/telegram-webhook/{telegramAccount}
     */
    public function handleWebhook(Request $request, TelegramAccount $telegramAccount): JsonResponse
    {
        // ── 1. Security Check: Validate Secret Token ──────────────────────────
        $secretHeader = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (! empty($telegramAccount->verify_token) && ! empty($secretHeader)) {
            if (! hash_equals($telegramAccount->verify_token, $secretHeader)) {
                Log::channel('stack')->warning("[TELEGRAM] Secret token mismatch for account #{$telegramAccount->id}");

                return response()->json(['status' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
        }

        // ── 2. Check Bot Status ───────────────────────────────────────────────
        if (! $telegramAccount->isActive()) {
            Log::channel('stack')->info("[TELEGRAM] Bot #{$telegramAccount->id} is inactive, update ignored.");

            return response()->json(['status' => 'account_inactive'], Response::HTTP_OK);
        }

        $update = $request->all();

        // ── 3. Extract Message Event ──────────────────────────────────────────
        $message = $update['message'] ?? null;
        if (! $message) {
            return response()->json(['status' => 'no_message_event'], Response::HTTP_OK);
        }

        $messageText = trim((string) ($message['text'] ?? ''));
        if (empty($messageText)) {
            Log::channel('stack')->info('[TELEGRAM] Non-text message ignored (attachment/sticker/etc.)');

            return response()->json(['status' => 'non_text_ignored'], Response::HTTP_OK);
        }

        $messageId = (string) ($message['message_id'] ?? '');
        $chatId = (string) ($message['chat']['id'] ?? '');
        $senderId = $chatId;

        if (empty($senderId)) {
            return response()->json(['status' => 'no_sender_id'], Response::HTTP_OK);
        }

        // ── 4. Deduplication Layer 1: Unique Message ID ───────────────────────
        if (! empty($messageId)) {
            $midCacheKey = "tg_msg_id_{$telegramAccount->id}_{$messageId}";
            if (! Cache::add($midCacheKey, true, now()->addMinutes(15))) {
                Log::channel('stack')->info("[TELEGRAM] Duplicate message ignored (MID cached): {$messageId}");

                return response()->json(['status' => 'duplicate_mid_ignored'], Response::HTTP_OK);
            }

            if (Chat::where('telegram_account_id', $telegramAccount->id)->where('meta_message_id', $messageId)->exists()) {
                Log::channel('stack')->info("[TELEGRAM] Duplicate message ignored (MID exists in Chat DB): {$messageId}");

                return response()->json(['status' => 'duplicate_db_ignored'], Response::HTTP_OK);
            }
        }

        // ── 5. Deduplication Layer 2: Sender + Content Fingerprint (8s window) ──
        $contentHash = md5("{$senderId}_{$messageText}");
        $fpCacheKey = "tg_msg_fp_{$telegramAccount->id}_{$contentHash}";
        if (! Cache::add($fpCacheKey, true, now()->addSeconds(8))) {
            Log::channel('stack')->info("[TELEGRAM] Duplicate message ignored (identical text from {$senderId} within 8s)");

            return response()->json(['status' => 'duplicate_fingerprint_ignored'], Response::HTTP_OK);
        }

        // ── 6. Check Active Subscription & Quota ──────────────────────────────
        if (! $telegramAccount->hasActiveSubscription()) {
            Log::channel('stack')->warning("[TELEGRAM] Limit exceeded or inactive subscription for account #{$telegramAccount->id}. No reply sent.");

            return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
        }

        // ── 7. Resolve Customer Name ──────────────────────────────────────────
        $from = $message['from'] ?? [];
        $firstName = trim((string) ($from['first_name'] ?? ''));
        $lastName = trim((string) ($from['last_name'] ?? ''));
        $username = trim((string) ($from['username'] ?? ''));

        $senderName = trim("{$firstName} {$lastName}");
        if (empty($senderName) && ! empty($username)) {
            $senderName = "@{$username}";
        }
        if (empty($senderName)) {
            $senderName = 'Telegram User';
        }

        // ── 8. Store Incoming Customer Message ────────────────────────────────
        Chat::create([
            'user_id' => $telegramAccount->user_id,
            'telegram_account_id' => $telegramAccount->id,
            'telegram_sender_id' => $senderId,
            'channel' => 'telegram',
            'name' => $senderName,
            'message' => $messageText,
            'is_image' => false,
            'is_admin' => false,
            'is_read' => false,
            'sender_type' => 'customer',
            'meta_message_id' => $messageId,
        ]);

        // ── 9. Generate AI Reply (Same Logic as Messenger) ────────────────────
        $aiReply = $this->telegramService->generateAiReply($telegramAccount, $messageText);

        if (empty($aiReply)) {
            $aiReply = 'أهلاً بك! نسعد بخدمتك.';
        }

        // ── 10. Send Reply to Customer via Telegram API ───────────────────────
        $sendResult = $this->telegramService->sendMessage(
            $telegramAccount->bot_token,
            $chatId,
            $aiReply
        );

        $sentMessageId = (string) ($sendResult['message_id'] ?? '');

        // ── 11. Store Outgoing Bot Message ────────────────────────────────────
        Chat::create([
            'user_id' => $telegramAccount->user_id,
            'telegram_account_id' => $telegramAccount->id,
            'telegram_sender_id' => $senderId,
            'channel' => 'telegram',
            'name' => $telegramAccount->bot_name ?: 'Bot',
            'message' => $aiReply,
            'is_image' => false,
            'is_admin' => true,
            'is_read' => true,
            'read_at' => now(),
            'sender_type' => 'bot',
            'meta_message_id' => $sentMessageId,
        ]);

        // ── 12. Decrement Quota & Record MsgSend ───────────────────────────────
        if ($telegramAccount->msg_number > 0) {
            $telegramAccount->decrement('msg_number');
        }

        MsgSend::create([
            'user_id' => $telegramAccount->user_id,
            'telegram_account_id' => $telegramAccount->id,
            'channel' => 'telegram',
        ]);

        return response()->json([
            'status' => 'ok',
            'reply_sent' => true,
        ], Response::HTTP_OK);
    }
}
