<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\MsgSend;
use App\Models\TikTokAccount;
use App\Services\TikTokService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TikTokWebhookController extends Controller
{
    public function __construct(
        protected TikTokService $tiktokService
    ) {}

    /**
     * Handle TikTok Webhook endpoint (GET for challenge / verification, POST for events).
     */
    public function handleWebhook(Request $request): Response|JsonResponse
    {
        // ── 1. GET: Webhook Verification / Challenge ─────────────────────────
        if ($request->isMethod('get')) {
            return $this->handleVerification($request);
        }

        // ── 2. POST: Event Notifications ─────────────────────────────────────
        Log::channel('stack')->info('[TIKTOK_WEBHOOK] ⬇ Incoming POST', [
            'ip' => $request->ip(),
            'payload' => $request->all(),
        ]);

        $payload = $request->all();

        // Check if TikTok sends a verification challenge in POST body
        if (isset($payload['challenge']) || ($payload['event'] ?? '') === 'verify') {
            return response()->json([
                'challenge' => (int) ($payload['challenge'] ?? $request->query('challenge', 0)),
            ], Response::HTTP_OK);
        }

        // ── 3. Security / Signature Check (if signature headers present) ───────
        $signature = $request->header('X-Tiktok-Signature') ?: $request->header('Tiktok-Signature');
        $timestamp = $request->header('X-Tiktok-Signature-Timestamp') ?: $request->header('Tiktok-Signature-Timestamp');

        if (! empty($signature) && config('services.tiktok.client_secret')) {
            $rawContent = (string) $request->getContent();
            $isValid = $this->tiktokService->verifyWebhookSignature($rawContent, $signature, $timestamp);

            if (! $isValid) {
                Log::channel('stack')->warning('[TIKTOK_WEBHOOK] ✗ Signature mismatch');

                return response()->json(['status' => 'unauthorized_signature'], Response::HTTP_UNAUTHORIZED);
            }
        }

        try {
            $event = (string) ($payload['event'] ?? ($payload['entry'][0]['event'] ?? ''));

            // Route between Comments and Direct Messages
            if (str_contains($event, 'comment') || isset($payload['comment_id']) || isset($payload['data']['comment_id'])) {
                return $this->handleCommentEvent($payload);
            }

            if (str_contains($event, 'message') || str_contains($event, 'im') || isset($payload['message']) || isset($payload['data']['message'])) {
                return $this->handleDirectMessageEvent($payload);
            }

            Log::channel('stack')->info("[TIKTOK_WEBHOOK] Unhandled event: {$event}");

            return response()->json(['status' => 'event_unhandled'], Response::HTTP_OK);
        } catch (\Throwable $e) {
            Log::channel('stack')->error('[TIKTOK_WEBHOOK] ✗ Processing error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['status' => 'internal_error', 'message' => $e->getMessage()], Response::HTTP_OK);
        }
    }

    /**
     * Respond to GET verification / challenge handshake from TikTok.
     */
    protected function handleVerification(Request $request): Response|JsonResponse
    {
        $challenge = $request->query('challenge') ?: $request->query('hub_challenge');
        $verifyToken = $request->query('verify_token') ?: $request->query('hub_verify_token');

        $expectedToken = config('services.tiktok.verify_token');

        if ($expectedToken && $verifyToken && ! hash_equals($expectedToken, (string) $verifyToken)) {
            Log::channel('stack')->warning('[TIKTOK_WEBHOOK] ✗ Verify token mismatch on GET verification');

            return response('Unauthorized', Response::HTTP_FORBIDDEN);
        }

        if ($challenge) {
            return response((string) $challenge, Response::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        return response()->json(['status' => 'ok', 'message' => 'TikTok Webhook verification endpoint active.']);
    }

    /**
     * Process TikTok Video Comment events and trigger AI reply.
     */
    public function handleCommentEvent(array $payload): JsonResponse
    {
        $data = $payload['data'] ?? $payload;

        $commentId = (string) ($data['comment_id'] ?? ($data['id'] ?? ''));
        $openId = (string) ($data['business_id'] ?? ($data['open_id'] ?? ($data['creator_id'] ?? ($payload['user_id'] ?? ''))));
        $commenterId = (string) ($data['commenter_id'] ?? ($data['from_user_id'] ?? ($data['user_id'] ?? '')));
        $commentText = trim((string) ($data['text'] ?? ($data['content'] ?? ($data['message'] ?? ''))));

        if (empty($commentId) || empty($commentText)) {
            Log::channel('stack')->warning('[TIKTOK_COMMENTS] ✗ Empty comment_id or text in payload');

            return response()->json(['status' => 'empty_comment_or_id'], Response::HTTP_OK);
        }

        // Prevent infinite loops: ignore comments made by the business account itself
        if ($openId && $commenterId && $openId === $commenterId) {
            Log::channel('stack')->info("[TIKTOK_COMMENTS] Ignored comment from business itself ({$openId})");

            return response()->json(['status' => 'self_comment_ignored'], Response::HTTP_OK);
        }

        // Prevent duplicate processing if TikTok retries
        $cacheKey = "tiktok_comment_replied_{$commentId}";
        if (Cache::has($cacheKey)) {
            Log::channel('stack')->info("[TIKTOK_COMMENTS] Deduplication hit for comment {$commentId}");

            return response()->json(['status' => 'already_processed'], Response::HTTP_OK);
        }
        Cache::put($cacheKey, true, now()->addMinutes(15));

        // Resolve TikTok Account
        $account = null;
        if (! empty($openId)) {
            $account = TikTokAccount::where('open_id', $openId)->first();
        }

        if (! $account) {
            $account = TikTokAccount::where('status', 'active')->first();
        }

        if (! $account || ! $account->isActive()) {
            Log::channel('stack')->warning("[TIKTOK_COMMENTS] Account not found or inactive for open_id: {$openId}");

            return response()->json(['status' => 'account_not_found_or_inactive'], Response::HTTP_OK);
        }

        // Check subscription & message quota
        if (! $account->hasActiveSubscription()) {
            Log::channel('stack')->info("[TIKTOK_COMMENTS] Account #{$account->id} has expired subscription or zero quota.");

            return response()->json(['status' => 'quota_exceeded_or_subscription_expired'], Response::HTTP_OK);
        }

        // Generate AI Reply
        $aiReply = $this->tiktokService->generateAiReply($account, $commentText);
        if (empty($aiReply)) {
            $aiReply = 'أهلاً بك! نسعد بخدمتك.';
        }

        // Send reply to TikTok Comment API
        $replyResult = $this->tiktokService->replyToComment($account, $commentId, $aiReply);

        if (! $replyResult['success']) {
            Log::channel('stack')->error('[TIKTOK_COMMENTS] Failed to post comment reply: '.($replyResult['message'] ?? 'Unknown error'));
        }

        // Decrement quota and record message send
        if ($account->msg_number > 0) {
            $account->decrement('msg_number');
        }

        MsgSend::create([
            'user_id' => $account->user_id,
            'tiktok_account_id' => $account->id,
            'channel' => 'tiktok',
        ]);

        return response()->json([
            'status' => 'comment_replied',
            'reply' => $aiReply,
            'api_result' => $replyResult,
        ], Response::HTTP_OK);
    }

    /**
     * Process TikTok Direct Message (Chat) events and trigger AI reply.
     */
    public function handleDirectMessageEvent(array $payload): JsonResponse
    {
        $data = $payload['data'] ?? $payload;

        $messageData = $data['message'] ?? $data;
        $messageId = (string) ($messageData['message_id'] ?? ($messageData['id'] ?? uniqid('tt_msg_')));
        $senderOpenId = (string) ($messageData['sender_id'] ?? ($data['from_user_id'] ?? ($data['user_id'] ?? '')));
        $recipientOpenId = (string) ($messageData['recipient_id'] ?? ($data['to_user_id'] ?? ($data['business_id'] ?? '')));
        $messageText = trim((string) ($messageData['text'] ?? ($messageData['content'] ?? '')));
        $senderName = trim((string) ($messageData['sender_name'] ?? 'TikTok User'));

        if (empty($senderOpenId) || empty($messageText)) {
            Log::channel('stack')->warning('[TIKTOK_CHAT] ✗ Empty sender_id or message text');

            return response()->json(['status' => 'empty_sender_or_text'], Response::HTTP_OK);
        }

        // Prevent duplicate processing
        $cacheKey = "tiktok_dm_{$messageId}";
        if (Cache::has($cacheKey)) {
            Log::channel('stack')->info("[TIKTOK_CHAT] Deduplication hit for message {$messageId}");

            return response()->json(['status' => 'already_processed'], Response::HTTP_OK);
        }
        Cache::put($cacheKey, true, now()->addMinutes(15));

        // Resolve TikTok account (the recipient of customer message)
        $account = null;
        if (! empty($recipientOpenId)) {
            $account = TikTokAccount::where('open_id', $recipientOpenId)->first();
        }

        if (! $account) {
            $account = TikTokAccount::where('status', 'active')->first();
        }

        if (! $account || ! $account->isActive()) {
            Log::channel('stack')->warning("[TIKTOK_CHAT] Account not found or inactive for recipient: {$recipientOpenId}");

            return response()->json(['status' => 'account_not_found_or_inactive'], Response::HTTP_OK);
        }

        // Prevent replying to own outgoing messages
        if ($senderOpenId === $account->open_id) {
            Log::channel('stack')->info("[TIKTOK_CHAT] Echo message from account itself ignored ({$senderOpenId})");

            return response()->json(['status' => 'echo_ignored'], Response::HTTP_OK);
        }

        // Store Incoming Customer Message in chats table
        Chat::create([
            'user_id' => $account->user_id,
            'tiktok_account_id' => $account->id,
            'tiktok_sender_id' => $senderOpenId,
            'name' => $senderName,
            'channel' => 'tiktok',
            'message' => $messageText,
            'is_image' => false,
            'is_admin' => false,
            'is_read' => false,
            'sender_type' => 'customer',
            'meta_message_id' => $messageId,
        ]);

        // Check subscription & quota
        if (! $account->hasActiveSubscription()) {
            Log::channel('stack')->info("[TIKTOK_CHAT] Account #{$account->id} quota exceeded or subscription expired.");

            return response()->json(['status' => 'quota_exceeded_or_subscription_expired'], Response::HTTP_OK);
        }

        // Generate AI Reply
        $aiReply = $this->tiktokService->generateAiReply($account, $messageText);
        if (empty($aiReply)) {
            $aiReply = 'أهلاً بك! نسعد بخدمتك.';
        }

        // Send Reply to Customer via TikTok Direct Message API
        $sendResult = $this->tiktokService->sendDirectMessage($account, $senderOpenId, $aiReply);

        $sentMessageId = (string) ($sendResult['message_id'] ?? '');

        // Store Outgoing Bot Message in chats table
        Chat::create([
            'user_id' => $account->user_id,
            'tiktok_account_id' => $account->id,
            'tiktok_sender_id' => $senderOpenId,
            'name' => $account->display_name ?: 'TikTok Bot',
            'channel' => 'tiktok',
            'message' => $aiReply,
            'is_image' => false,
            'is_admin' => true,
            'is_read' => true,
            'read_at' => now(),
            'sender_type' => 'bot',
            'meta_message_id' => $sentMessageId,
        ]);

        // Decrement quota and record message send
        if ($account->msg_number > 0) {
            $account->decrement('msg_number');
        }

        MsgSend::create([
            'user_id' => $account->user_id,
            'tiktok_account_id' => $account->id,
            'channel' => 'tiktok',
        ]);

        return response()->json([
            'status' => 'message_replied',
            'reply' => $aiReply,
            'send_result' => $sendResult,
        ], Response::HTTP_OK);
    }
}
