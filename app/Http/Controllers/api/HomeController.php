<?php

namespace App\Http\Controllers\api;

use App\Events\InstagramEvent;
use App\Events\MessengerEvent;
use App\Events\TypingEvent;
use App\Events\WhatsEvent;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Food;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;

class HomeController extends Controller
{
    /**
     * Base WhatsApp Graph API URL.
     */
    private const GRAPH_API_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Main webhook entry point.
     * Handles Meta verification challenges and incoming messages.
     */
    public function test_webhook(Request $request): Response|JsonResponse
    {
        $new_chat = [
            'user_id' => 1,
            'whats_item_id' => 1,
            'name' => 'Ahmed',
            'phone' => '201206610346',
            'message' => 'Hello, this is a test message from the webhook.',
            'is_image' => false,
            'is_admin' => false,
            'sender_type' => 'customer',
            'is_read' => false,
            'channel' => 'whatsapp',
            'messenger_sender_id' => 29269086176028063,
            'page_id' => 106565280821724,
        ];
        try {
            MessengerEvent::dispatch($new_chat);

            return response()->json(['status' => 'success'], Response::HTTP_OK);
        } catch (\Throwable $broadcastException) {
            return response()->json([
                'status' => 'error',
                'message' => 'MessengerEvent broadcast failed: '.$broadcastException->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function web_hook(Request $request)
    {
        // 1. Handle Meta webhook verification (GET challenge)
        if ($request->isMethod('get')) {
            return $this->verify($request);
        }

        try {
            $data = $request->all();

            // 2. Resolve the restaurant user via phone_number_id from WhatsItem
            $phoneNumberId = data_get($data, 'entry.0.changes.0.value.metadata.phone_number_id');

            // Handle Meta test button from Developer Dashboard (sends dummy ID 123456123)
            if ((string) $phoneNumberId === '123456123') {
                $phoneNumberId = config('services.meta.phone_number_id', '1296872370175605');
            }

            if (! $phoneNumberId) {
                return response()->json(['status' => 'ignored'], Response::HTTP_OK);
            }

            /** @var WhatsItem|null $whatsItem */
            $whatsItem = WhatsItem::where('phone_number_id', $phoneNumberId)->first();

            if (! $whatsItem) {
                Log::warning("Webhook received for unknown phone_number_id: {$phoneNumberId}");

                return response()->json(['status' => 'restaurant_not_found'], Response::HTTP_OK);
            }

            /** @var User $restaurant */
            $restaurant = $whatsItem->user;

            if (! $restaurant) {
                return response()->json(['status' => 'restaurant_not_found'], Response::HTTP_OK);
            }

            // 3. Extract incoming message
            $incomingMessage = data_get($data, 'entry.0.changes.0.value.messages.0');

            if (! $incomingMessage) {
                return response()->json(['status' => 'no_message'], Response::HTTP_OK);
            }

            $senderPhone = (string) $incomingMessage['from'];

            // If Meta or manual test sends dummy test sender, route reply to restaurant's verified phone
            if (in_array($senderPhone, ['16315551181', '01000000000', '123456789', '123456123'], true)) {
                $senderPhone = $whatsItem->phone ?: ($restaurant->phone ?: '201206610346');
            }

            // Normalize local Egyptian format (01xxxxxxxxx -> 201xxxxxxxxx)
            if (str_starts_with($senderPhone, '01') && strlen($senderPhone) === 11) {
                $senderPhone = '2'.$senderPhone;
            }

            $senderName = data_get($data, 'entry.0.changes.0.value.contacts.0.profile.name', 'عميل');
            $messageText = trim(data_get($incomingMessage, 'text.body', ''));

            // Ignore non-text messages (images, stickers, etc.)
            if (empty($messageText)) {
                return response()->json(['status' => 'non_text_ignored'], Response::HTTP_OK);
            }

            Log::info('Webhook: message received', [
                'restaurant_id' => $restaurant->id,
                'whats_item_id' => $whatsItem->id,
                'sender' => $senderPhone,
                'message' => $messageText,
            ]);

            // 4. Check if the WhatsItem has an active subscription with remaining messages
            if (! $whatsItem->hasActiveSubscription()) {
                Log::info("Webhook: message limit reached or inactive subscription for WhatsItem #{$whatsItem->id} (restaurant #{$restaurant->id})");

                return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
            }

            // 5. Save the customer's incoming message
            $new_chat = Chat::create([
                'user_id' => $restaurant->id,
                'whats_item_id' => $whatsItem->id,
                'name' => $senderName,
                'phone' => $senderPhone,
                'message' => $messageText,
                'is_image' => false,
                'is_admin' => false,
                'sender_type' => 'customer',
                'is_read' => false,
                'channel' => 'whatsapp',
                'meta_message_id' => data_get($incomingMessage, 'id'),
            ]);
            // Broadcast to admin dashboard (non-blocking — failure must not stop AI reply)
            try {
                WhatsEvent::dispatch($new_chat);
            } catch (\Throwable $broadcastException) {
                Log::warning('WhatsApp WhatsEvent broadcast failed (non-fatal): '.$broadcastException->getMessage());
            }

            // 6. Broadcast typing indicator to admin dashboard (Realtime dots)
            try {
                TypingEvent::dispatch(
                    channel: 'whatsapp',
                    phone: $senderPhone,
                    senderId: null,
                    pageId: null,
                    isTyping: true,
                );
            } catch (\Throwable $broadcastException) {
                Log::warning('WhatsApp TypingEvent broadcast failed (non-fatal): '.$broadcastException->getMessage());
            }

            // Mark customer message as read (shows ✓✓ in WhatsApp)
            $incomingMessageId = data_get($incomingMessage, 'id');
            $token = $whatsItem->access_token ?: config('services.meta.system_user_token');
            if ($incomingMessageId) {
                $this->showWhatsAppTyping(
                    accessToken: (string) $token,
                    phoneNumberId: $whatsItem->phone_number_id,
                    senderPhone: $senderPhone,
                    incomingMessageId: $incomingMessageId,
                );
            }

            // 7. Get AI reply
            $reply = $this->getAiReply($restaurant, $messageText, $whatsItem);

            if (! $reply) {
                Log::warning("Webhook: AI returned empty reply for restaurant #{$restaurant->id}");

                return response()->json(['status' => 'ai_failed'], Response::HTTP_OK);
            }

            // 7. Send reply via WhatsApp — only record to DB if successful
            $token = $whatsItem->access_token ?: config('services.meta.system_user_token');
            $sent = $this->sendTextMessage(
                accessToken: (string) $token,
                phoneNumberId: $whatsItem->phone_number_id,
                to: $senderPhone,
                body: $reply,
            );

            if ($sent) {
                if ((int) $whatsItem->msg_number > 0) {
                    $whatsItem->decrement('msg_number');
                }

                Chat::create([
                    'user_id' => $restaurant->id,
                    'whats_item_id' => $whatsItem->id,
                    'name' => $senderName,
                    'phone' => $senderPhone,
                    'message' => $reply,
                    'is_image' => false,
                    'is_admin' => true,
                    'sender_type' => 'bot',
                    'is_read' => true,
                    'channel' => 'whatsapp',
                ]);

                MsgSend::create([
                    'user_id' => $restaurant->id,
                    'whats_item_id' => $whatsItem->id,
                    'messenger_account_id' => null,
                    'channel' => 'whatsapp',
                ]);
            } else {
                Log::warning("Webhook: WhatsApp send failed for restaurant #{$restaurant->id} to {$senderPhone}");
            }

            return response()->json([
                'status' => 'success',
                'reply' => $reply,
                'whatsapp_sent' => $sent,
            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            Log::error('Webhook exception: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            // Always return 200 to prevent Meta from retrying endlessly, but include error details for debugging
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], Response::HTTP_OK);
        }
    }

    /**
     * Meta webhook verification endpoint (used during setup).
     */
    public function verify(Request $request)
    {
        $verifyToken = config('services.meta.verify_token');
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        Log::info('Webhook verify attempt', [
            'hub_mode' => $mode,
            'token_match' => $token === $verifyToken,
            'ip' => $request->ip(),
        ]);

        if ($mode === 'subscribe' && $token === $verifyToken) {
            Log::info('Webhook verified successfully.');

            return response((string) $challenge, Response::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        Log::warning('Webhook verification failed: token mismatch or wrong mode.', [
            'hub_mode' => $mode,
            'received_token' => $token,
            'expected_token' => $verifyToken ? '***' : 'NOT_SET',
        ]);

        return response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Determine whether the restaurant still has messages left in its active subscription.
     *
     * @param  string  $channel  'whatsapp' | 'messenger' | 'instagram'
     */
    private function hasRemainingMessages(User $restaurant, string $channel = 'whatsapp'): bool
    {
        $today = now()->toDateString();

        $packageType = match ($channel) {
            'whatsapp' => 'whats',
            'messenger' => 'face',
            'instagram' => 'instagram',
            default => $channel,
        };

        $orderQuery = Order::where('user_id', $restaurant->id)
            ->where('from', '<=', $today)
            ->where('to', '>=', $today)
            ->where(function ($q) use ($channel, $packageType) {
                $q->where('channel', $channel)
                    ->orWhereHas('package', fn ($pq) => $pq->whereIn('type', [$packageType, 'all']));
            });

        $activeOrder = (clone $orderQuery)->sum('msgs');
        $from = (clone $orderQuery)->min('from');
        $to = (clone $orderQuery)->max('to');

        if (! $activeOrder) {
            return false;
        }

        $used = MsgSend::where('user_id', $restaurant->id)
            ->where('channel', $channel)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->count();

        return $used < $activeOrder;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Messenger Webhook
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Main Messenger webhook entry point.
     * GET  → verify Meta webhook challenge (per-page verify_token stored in DB)
     * POST → handle incoming Messenger messages and send AI replies
     */
    public function messenger_web_hook(Request $request): Response|JsonResponse
    {
        if ($request->isMethod('get')) {
            return $this->messengerVerify($request);
        }

        // ── Log every incoming POST immediately (before any processing)
        Log::channel('stack')->info('[MESSENGER] ⬇ Incoming POST', [
            'ip' => $request->ip(),
            'payload' => $request->all(),
        ]);

        try {
            $data = $request->all();

            $object = data_get($data, 'object');

            // Only handle page-level Messenger events
            if ($object !== 'page') {
                Log::channel('stack')->warning("[MESSENGER] ✗ Ignored — object is '{$object}', expected 'page'");

                return response()->json(['status' => 'ignored_non_page'], Response::HTTP_OK);
            }

            $pageId = (string) data_get($data, 'entry.0.id');

            Log::channel('stack')->info("[MESSENGER] ✓ object=page | page_id={$pageId}");

            if (! $pageId) {
                Log::channel('stack')->error('[MESSENGER] ✗ No page_id in payload');

                return response()->json(['status' => 'no_page_id'], Response::HTTP_OK);
            }

            // Check if this is a Feed Change event (comments / post interactions)
            if (data_get($data, 'entry.0.changes.0')) {
                return $this->handleFacebookFeedChange($data, $pageId);
            }

            // Resolve restaurant via the Facebook Page ID
            /** @var MessengerAccount|null $messengerAccount */
            $messengerAccount = MessengerAccount::where('page_id', $pageId)
                ->where('status', 'active')
                ->first();

            if (! $messengerAccount) {
                // Check if page exists but is disabled
                $disabledAccount = MessengerAccount::where('page_id', $pageId)->first();

                Log::channel('stack')->warning('[MESSENGER] ✗ Page not found or disabled', [
                    'page_id' => $pageId,
                    'exists_in_db' => (bool) $disabledAccount,
                    'disabled_status' => $disabledAccount?->status,
                ]);

                return response()->json(['status' => 'page_not_found'], Response::HTTP_OK);
            }

            Log::channel('stack')->info('[MESSENGER] ✓ Page found', [
                'page_id' => $pageId,
                'page_name' => $messengerAccount->page_name,
                'messenger_account_id' => $messengerAccount->id,
                'user_id' => $messengerAccount->user_id,
            ]);

            /** @var User $restaurant */
            $restaurant = $messengerAccount->user;

            // Extract the first messaging event
            $messagingEvent = data_get($data, 'entry.0.messaging.0');

            if (! $messagingEvent) {
                Log::channel('stack')->warning('[MESSENGER] ✗ No messaging event found in entry.0.messaging.0', [
                    'raw_entry' => data_get($data, 'entry.0'),
                ]);

                return response()->json(['status' => 'no_messaging_event'], Response::HTTP_OK);
            }

            // Ignore echoed messages (sent by the page itself)
            if (data_get($messagingEvent, 'message.is_echo')) {
                Log::channel('stack')->info('[MESSENGER] ✓ Echo ignored (sent by page)');

                return response()->json(['status' => 'echo_ignored'], Response::HTTP_OK);
            }

            $senderId = (string) data_get($messagingEvent, 'sender.id');
            $messageText = trim((string) data_get($messagingEvent, 'message.text', ''));

            Log::channel('stack')->info('[MESSENGER] ✓ Message event', [
                'sender_psid' => $senderId,
                'message_text' => $messageText,
                'mid' => data_get($messagingEvent, 'message.mid'),
            ]);

            // Ignore non-text messages (attachments, stickers, etc.)
            if (empty($messageText)) {
                Log::channel('stack')->info('[MESSENGER] ✗ Ignored — non-text message (attachment/sticker)', [
                    'raw_message' => data_get($messagingEvent, 'message'),
                ]);

                return response()->json(['status' => 'non_text_ignored'], Response::HTTP_OK);
            }

            // Check Messenger-specific message limit on the MessengerAccount
            if (! $messengerAccount->hasActiveSubscription()) {
                Log::channel('stack')->warning("[MESSENGER] ✗ Limit exceeded or inactive subscription for account #{$messengerAccount->id} (restaurant #{$restaurant->id})");

                try {
                    if (! empty($messengerAccount->page_access_token)) {
                        $fallbackReply = 'أهلاً بك! شكراً لتواصلك معنا. فريق خدمة العملاء سيتواصل معك في أقرب وقت للرد على استفسارك بالتفصيل. يسعدنا دائماً خدمتك!';
                        $this->sendMessengerMessage(
                            pageAccessToken: $messengerAccount->page_access_token,
                            recipientId: $senderId,
                            text: $fallbackReply,
                        );
                    }
                } catch (\Throwable $fallbackException) {
                    Log::warning('[MESSENGER] Fallback send failed: '.$fallbackException->getMessage());
                }

                return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
            }

            // Save incoming customer message
            $new_chat = Chat::create([
                'user_id' => $restaurant->id,
                'messenger_account_id' => $messengerAccount->id,
                'name' => 'Messenger User',
                'phone' => null,
                'message' => $messageText,
                'is_image' => false,
                'is_admin' => false,
                'sender_type' => 'customer',
                'is_read' => false,
                'channel' => 'messenger',
                'messenger_sender_id' => $senderId,
                'meta_message_id' => data_get($messagingEvent, 'message.mid'),
            ]);
            $chatData = $new_chat->toArray();
            $chatData['page_id'] = $messengerAccount->page_id;

            // Broadcast to admin dashboard (non-blocking — failure must not stop AI reply)
            try {
                MessengerEvent::dispatch($chatData);
            } catch (\Throwable $broadcastException) {
                Log::warning('[MESSENGER] ⚠ MessengerEvent broadcast failed (non-fatal): '.$broadcastException->getMessage());
            }

            Log::channel('stack')->info('[MESSENGER] ✓ Customer message saved to DB');

            // Broadcast typing indicator to admin dashboard (Realtime dots)
            try {
                TypingEvent::dispatch(
                    channel: 'messenger',
                    phone: null,
                    senderId: $senderId,
                    pageId: $messengerAccount->page_id,
                    isTyping: true,
                );
            } catch (\Throwable $broadcastException) {
                Log::warning('[MESSENGER] ⚠ TypingEvent broadcast failed (non-fatal): '.$broadcastException->getMessage());
            }

            // Show typing dots and keep them alive every 15s during AI processing
            Log::channel('stack')->info('[MESSENGER] ⏳ Calling OpenAI with persistent typing indicator...');
            $reply = $this->getMessengerAiReplyWithTyping(
                messengerAccount: $messengerAccount,
                userMessage: $messageText,
                senderId: $senderId,
            );

            if (! $reply) {
                Log::channel('stack')->warning("[MESSENGER] ✗ OpenAI returned empty reply for restaurant #{$restaurant->id}");

                return response()->json(['status' => 'ai_failed'], Response::HTTP_OK);
            }

            Log::channel('stack')->info('[MESSENGER] ✓ OpenAI replied', [
                'reply_preview' => mb_substr($reply, 0, 100),
            ]);

            // Send reply via Messenger API
            Log::channel('stack')->info('[MESSENGER] ⏳ Sending reply via Messenger API...', [
                'recipient_psid' => $senderId,
            ]);

            $sent = $this->sendMessengerMessage(
                pageAccessToken: $messengerAccount->page_access_token,
                recipientId: $senderId,
                text: $reply,
            );

            Log::channel('stack')->info('[MESSENGER] ✓ Messenger API send result', [
                'sent' => $sent,
                'recipient_psid' => $senderId,
            ]);

            if ($sent) {
                if ((int) $messengerAccount->msg_number > 0) {
                    $messengerAccount->decrement('msg_number');
                }

                Chat::create([
                    'user_id' => $restaurant->id,
                    'messenger_account_id' => $messengerAccount->id,
                    'name' => 'Messenger User',
                    'phone' => null,
                    'message' => $reply,
                    'is_image' => false,
                    'is_admin' => true,
                    'sender_type' => 'bot',
                    'is_read' => true,
                    'channel' => 'messenger',
                    'messenger_sender_id' => $senderId,
                ]);

                MsgSend::create([
                    'user_id' => $restaurant->id,
                    'messenger_account_id' => $messengerAccount->id,
                    'whats_item_id' => null,
                    'channel' => 'messenger',
                ]);

                Log::channel('stack')->info('[MESSENGER] ✅ Full flow complete — reply saved & sent');
            } else {
                Log::channel('stack')->error("[MESSENGER] ✗ Messenger API send FAILED for restaurant #{$restaurant->id} → PSID {$senderId}");
            }

            return response()->json([
                'status' => 'success',
                'reply' => $reply,
                'messenger_sent' => $sent,
            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            Log::channel('stack')->error('[MESSENGER] 💥 EXCEPTION: '.$e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->implode("\n"),
                'payload' => $request->all(),
            ]);

            // Always return 200 to prevent Meta from retrying endlessly
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], Response::HTTP_OK);
        }
    }

    /**
     * Dedicated Facebook Comments Webhook entry point.
     * GET  → verify Meta challenge
     * POST → handle feed comments
     */
    public function facebook_comments_webhook(Request $request): Response|JsonResponse
    {
        if ($request->isMethod('get')) {
            return $this->messengerVerify($request);
        }

        return $this->messenger_web_hook($request);
    }

    /**
     * Handle incoming Facebook Feed Webhook events (comments on posts).
     *
     * @param  array<string, mixed>  $data
     */
    public function handleFacebookFeedChange(array $data, string $pageId): JsonResponse
    {
        Log::channel('stack')->info('[FB_COMMENTS] ⬇ Feed change received', [
            'page_id' => $pageId,
            'entry' => data_get($data, 'entry.0'),
        ]);

        $change = data_get($data, 'entry.0.changes.0');
        $item = (string) data_get($change, 'value.item');
        $verb = (string) data_get($change, 'value.verb');

        // Only process new comments being added
        if ($item !== 'comment' || $verb !== 'add') {
            Log::channel('stack')->info("[FB_COMMENTS] Ignored change — item='{$item}', verb='{$verb}'");

            return response()->json(['status' => 'ignored_non_comment_add'], Response::HTTP_OK);
        }

        $changeValue = data_get($change, 'value', []);
        $commentId = (string) data_get($changeValue, 'comment_id');
        $postId = (string) data_get($changeValue, 'post_id');
        $senderId = (string) data_get($changeValue, 'from.id');
        $senderName = trim((string) data_get($changeValue, 'from.name', 'عميل فيسبوك'));
        $commentText = trim((string) data_get($changeValue, 'message', ''));

        if (empty($commentId) || empty($commentText)) {
            Log::channel('stack')->warning('[FB_COMMENTS] ✗ Empty comment_id or message');

            return response()->json(['status' => 'empty_comment_or_id'], Response::HTTP_OK);
        }

        // Prevent infinite loops: ignore comments made by the page itself
        if ($senderId === $pageId) {
            Log::channel('stack')->info("[FB_COMMENTS] Ignored comment from page itself ({$pageId})");

            return response()->json(['status' => 'self_comment_ignored'], Response::HTTP_OK);
        }

        // Prevent duplicate processing if Meta retries
        $cacheKey = "fb_comment_replied_{$commentId}";
        if (Cache::has($cacheKey)) {
            Log::channel('stack')->info("[FB_COMMENTS] Comment {$commentId} already processed (idempotency check)");

            return response()->json(['status' => 'already_processed'], Response::HTTP_OK);
        }

        /** @var MessengerAccount|null $messengerAccount */
        $messengerAccount = MessengerAccount::where('page_id', $pageId)
            ->where('status', 'active')
            ->first();

        if (! $messengerAccount) {
            Log::channel('stack')->warning("[FB_COMMENTS] Page {$pageId} not found or inactive in MessengerAccount");

            return response()->json(['status' => 'page_not_found'], Response::HTTP_OK);
        }

        /** @var User $restaurant */
        $restaurant = $messengerAccount->user;

        // Verify active subscription and quota
        $today = now()->toDateString();
        $isWithinDates = false;
        if (! empty($messengerAccount->start_date) && ! empty($messengerAccount->end_date)) {
            $startDate = $messengerAccount->start_date instanceof Carbon
                ? $messengerAccount->start_date->toDateString()
                : (string) $messengerAccount->start_date;
            $endDate = $messengerAccount->end_date instanceof Carbon
                ? $messengerAccount->end_date->toDateString()
                : (string) $messengerAccount->end_date;

            $isWithinDates = ($startDate <= $today && $endDate >= $today);
        } else {
            $isWithinDates = $messengerAccount->hasActiveSubscription();
        }

        $hasRemainingQuota = ((int) $messengerAccount->msg_number >= 1);
        $isAiAvailable = ($isWithinDates && $hasRemainingQuota);

        // ── Branch A: AI/Quota is exhausted or expired ("لو ai خلصان")
        if (! $isAiAvailable) {
            Log::channel('stack')->warning("[FB_COMMENTS] AI / Quota exhausted for account #{$messengerAccount->id}. Sending universal fallback.");

            $fallbackCommentReply = "أهلاً بك يا {$senderName}! شكراً لتواصلك معنا، تم إرسال رسالة لحضرتك على الخاص ويسعدنا دائماً خدمتك.";
            $fallbackMessengerReply = "أهلاً بك يا {$senderName}! شكراً لاهتمامك وتواصلك معنا بخصوص المنشور. فريق خدمة العملاء سيتواصل معك في أقرب وقت للرد على استفسارك بالتفصيل ومساعدتك. نسعد دائماً بخدمتك!";

            $commentSent = $this->replyToFacebookComment(
                pageAccessToken: $messengerAccount->page_access_token,
                commentId: $commentId,
                message: $fallbackCommentReply,
            );

            $messengerSent = $this->sendPrivateReplyToComment(
                pageAccessToken: $messengerAccount->page_access_token,
                commentId: $commentId,
                message: $fallbackMessengerReply,
            );

            Cache::put($cacheKey, true, now()->addDays(7));

            return response()->json([
                'status' => 'fallback_sent',
                'reason' => 'quota_exhausted_or_expired',
                'comment_sent' => $commentSent,
                'messenger_sent' => (bool) $messengerSent,
            ], Response::HTTP_OK);
        }

        // ── Branch B: AI is available — process with OpenAI
        $postText = $this->getFacebookPostContent(
            pageAccessToken: $messengerAccount->page_access_token,
            postId: $postId,
        );

        $aiDecision = $this->getFacebookCommentAiDecision(
            messengerAccount: $messengerAccount,
            senderName: $senderName,
            commentText: $commentText,
            postText: $postText,
        );

        // If AI call failed, fallback gracefully to universal messages
        if (! $aiDecision) {
            Log::channel('stack')->warning("[FB_COMMENTS] AI call failed for account #{$messengerAccount->id}. Sending universal fallback.");

            $fallbackCommentReply = "أهلاً بك يا {$senderName}! شكراً لتواصلك معنا، تم إرسال رسالة لحضرتك على الخاص ويسعدنا دائماً خدمتك.";
            $fallbackMessengerReply = "أهلاً بك يا {$senderName}! شكراً لاهتمامك وتواصلك معنا بخصوص المنشور. فريق خدمة العملاء سيتواصل معك في أقرب وقت للرد على استفسارك بالتفصيل ومساعدتك. نسعد دائماً بخدمتك!";

            $commentSent = $this->replyToFacebookComment(
                pageAccessToken: $messengerAccount->page_access_token,
                commentId: $commentId,
                message: $fallbackCommentReply,
            );

            $messengerSent = $this->sendPrivateReplyToComment(
                pageAccessToken: $messengerAccount->page_access_token,
                commentId: $commentId,
                message: $fallbackMessengerReply,
            );

            Cache::put($cacheKey, true, now()->addDays(7));

            return response()->json([
                'status' => 'fallback_sent',
                'reason' => 'ai_service_failed',
                'comment_sent' => $commentSent,
                'messenger_sent' => (bool) $messengerSent,
            ], Response::HTTP_OK);
        }

        $isInquiry = (bool) ($aiDecision['is_inquiry'] ?? false);
        $publicCommentReply = trim((string) ($aiDecision['public_comment_reply'] ?? ''));
        $privateMessengerReply = trim((string) ($aiDecision['private_messenger_reply'] ?? ''));

        $commentSent = false;
        $messengerSent = null;

        if ($isInquiry) {
            if (empty($publicCommentReply)) {
                $publicCommentReply = "أهلاً بك يا {$senderName}! تم الرد على الخاص بالتفاصيل كاملة، يسعدنا تواصلك دائماً 😊";
            }

            // 1. Reply publicly on the post comment
            $commentSent = $this->replyToFacebookComment(
                pageAccessToken: $messengerAccount->page_access_token,
                commentId: $commentId,
                message: $publicCommentReply,
            );

            // 2. Send private reply on Messenger
            if (! empty($privateMessengerReply)) {
                $messengerSent = $this->sendPrivateReplyToComment(
                    pageAccessToken: $messengerAccount->page_access_token,
                    commentId: $commentId,
                    message: $privateMessengerReply,
                );
            }
        } else {
            // Not an inquiry: polite appreciation comment reply
            if (empty($publicCommentReply)) {
                $publicCommentReply = "شكراً جزيلاً لك يا {$senderName}! يسعدنا تواصلك ونتشرف بك دائماً ❤️";
            }

            $commentSent = $this->replyToFacebookComment(
                pageAccessToken: $messengerAccount->page_access_token,
                commentId: $commentId,
                message: $publicCommentReply,
            );
        }

        // Deduct 1 message from quota upon successful processing
        if ($commentSent || $messengerSent) {
            if ((int) $messengerAccount->msg_number > 0) {
                $messengerAccount->decrement('msg_number');
            }

            MsgSend::create([
                'user_id' => $restaurant->id,
                'messenger_account_id' => $messengerAccount->id,
                'whats_item_id' => null,
                'channel' => 'messenger',
            ]);

            // Save chat record if private message was sent
            if ($messengerSent && ! empty($privateMessengerReply)) {
                $recipientPsid = (string) ($messengerSent['recipient_id'] ?? $senderId);
                $metaMid = (string) ($messengerSent['message_id'] ?? $commentId);

                $newChat = Chat::create([
                    'user_id' => $restaurant->id,
                    'messenger_account_id' => $messengerAccount->id,
                    'name' => $senderName,
                    'phone' => null,
                    'message' => $privateMessengerReply,
                    'is_image' => false,
                    'is_admin' => true,
                    'sender_type' => 'bot',
                    'is_read' => true,
                    'channel' => 'messenger',
                    'messenger_sender_id' => $recipientPsid,
                    'meta_message_id' => $metaMid,
                ]);

                try {
                    $chatData = $newChat->toArray();
                    $chatData['page_id'] = $messengerAccount->page_id;
                    MessengerEvent::dispatch($chatData);
                } catch (\Throwable $e) {
                    Log::warning('[FB_COMMENTS] MessengerEvent broadcast failed: '.$e->getMessage());
                }
            }
        }

        Cache::put($cacheKey, true, now()->addDays(7));

        return response()->json([
            'status' => 'success',
            'is_inquiry' => $isInquiry,
            'comment_sent' => $commentSent,
            'messenger_sent' => (bool) $messengerSent,
            'public_comment_reply' => $publicCommentReply,
            'private_messenger_reply' => $privateMessengerReply,
        ], Response::HTTP_OK);
    }

    /**
     * Reply to a Facebook post comment publicly via Graph API.
     */
    private function replyToFacebookComment(string $pageAccessToken, string $commentId, string $message): bool
    {
        try {
            $response = Http::withToken($pageAccessToken)
                ->post(self::GRAPH_API_BASE."/{$commentId}/comments", [
                    'message' => $message,
                ]);

            if ($response->successful()) {
                Log::channel('stack')->info("[FB_COMMENTS] ✓ Comment reply sent to {$commentId}");

                return true;
            }

            Log::channel('stack')->error("[FB_COMMENTS] ✗ Comment reply failed for {$commentId}", [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::channel('stack')->error('[FB_COMMENTS] Exception sending comment reply: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Send a private reply to a Facebook comment via Messenger Send API.
     *
     * @return array<string, mixed>|null
     */
    private function sendPrivateReplyToComment(string $pageAccessToken, string $commentId, string $message): ?array
    {
        try {
            $response = Http::withToken($pageAccessToken)
                ->post(self::GRAPH_API_BASE.'/me/messages', [
                    'recipient' => [
                        'comment_id' => $commentId,
                    ],
                    'message' => [
                        'text' => $message,
                    ],
                ]);

            if ($response->successful()) {
                Log::channel('stack')->info("[FB_COMMENTS] ✓ Private reply sent via Messenger for comment {$commentId}", [
                    'response' => $response->json(),
                ]);

                return $response->json();
            }

            Log::channel('stack')->error("[FB_COMMENTS] ✗ Private reply failed for comment {$commentId}", [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::channel('stack')->error('[FB_COMMENTS] Exception sending private reply: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Fetch Facebook Post text/story context via Graph API.
     */
    private function getFacebookPostContent(string $pageAccessToken, string $postId): ?string
    {
        if (empty($postId)) {
            return null;
        }

        return Cache::remember("fb_post_content_{$postId}", 3600, function () use ($pageAccessToken, $postId) {
            try {
                $response = Http::withToken($pageAccessToken)
                    ->get(self::GRAPH_API_BASE."/{$postId}", [
                        'fields' => 'message,story',
                    ]);

                if ($response->successful()) {
                    return $response->json('message') ?: $response->json('story');
                }
            } catch (\Throwable $e) {
                Log::warning("[FB_COMMENTS] Could not fetch post {$postId}: ".$e->getMessage());
            }

            return null;
        });
    }

    /**
     * Get an AI-powered classification and reply decision for a Facebook comment.
     *
     * @return array{is_inquiry: bool, public_comment_reply: string, private_messenger_reply: ?string}|null
     */
    private function getFacebookCommentAiDecision(
        MessengerAccount $messengerAccount,
        string $senderName,
        string $commentText,
        ?string $postText = null,
    ): ?array {
        $aiContext = ! empty($messengerAccount->ai_context)
            ? $messengerAccount->ai_context
            : (Setting::firstWhere('name', 'ai_context')?->value ?? 'أنت موظف خدمة عملاء محترف، ردّ بأسلوب ودي ومهذب ومساعد.');

        $fileContent = $this->resolveAiFileContent($messengerAccount->ai_file);
        $fileDataSection = '';
        if (! empty($fileContent)) {
            $fileDataSection = "\n\nبيانات وقائمة المنتجات / الخدمات والمعلومات المتاحة:\n".$fileContent;
        }

        $linksSection = '';
        if ($messengerAccount->android_link || $messengerAccount->ios_link || $messengerAccount->website_url) {
            $linksSection = "\n\nروابط وتفاصيل الطلب المتاحة:";
            if ($messengerAccount->website_url) {
                $linksSection .= "\n- الموقع الإلكتروني: {$messengerAccount->website_url}";
            }
            if ($messengerAccount->android_link) {
                $linksSection .= "\n- تطبيق أندرويد (Android): {$messengerAccount->android_link}";
            }
            if ($messengerAccount->ios_link) {
                $linksSection .= "\n- تطبيق آيفون (iOS): {$messengerAccount->ios_link}";
            }
        }

        $instructions = <<<PROMPT
        {$aiContext}
        {$fileDataSection}
        {$linksSection}

        التعليمات الصارمة للرد:
        - أنت ممثل خدمة عملاء محترف ومؤدب جداً. استخدم لغة عربية ودودة، راقية، ومحترمة ومهذبة للغاية.
        - رحب بالعميل باسمه دائماً في بداية الرد (مثال: أهلاً وسهلاً بك يا {$senderName} 🌸).
        - العميل قام بكتابة تعليق على منشور لنا، لذا يجب أن يكون الرد الخاص على ماسنجر متصلاً بسياق البوست وسؤاله في التعليق.
        - أجب بدقة على استفساره بالاعتماد الحصري على "بيانات وقائمة المنتجات / الخدمات والمعلومات المتاحة" المذكورة أعلاه، ولا تخترع أي معلومات أو أسعار غير موجودة.
        - إذا سأل العميل عن شيء غير مذكور في البيانات أو غير متاح، اعتذر له بلباقة وأخبره أنه غير متوفر حالياً.
        - إذا طلب العميل أو سأل عن كيفية الطلب، وضح له بلباقة روابط الطلب المتوفرة أعلاه.
        - اختم الرسالة الخاصة دائماً بعبارة ترحيبية راقية مثل: «نسعد دائماً بخدمتك، ولو عندك أي استفسار آخر لا تتردد في مراسلتنا في أي وقت! 😊».

        يجب أن تعيد الناتج بتنسيق JSON فقط بدون أي علامات markdown:
        {
          "is_inquiry": true,
          "public_comment_reply": "نص الرد العام على التعليق في البوست",
          "private_messenger_reply": "نص الرسالة الخاصة الترحيبية المفصلة والمهذبة التي ستُرسل له على ماسنجر (إذا كان استفساراً)، أو null إذا لم يكن استفساراً"
        }
        PROMPT;

        $postSummary = ! empty($postText) ? $postText : '(منشور عام للصفحة)';
        $userInput = <<<INPUT
        بيانات تفاعل العميل:
        - اسم العميل: {$senderName}
        - محتوى المنشور (البوست) الذي علّق عليه:
        "{$postSummary}"

        - تعليق العميل على المنشور:
        "{$commentText}"
        INPUT;

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $rawOutput = '';
            try {
                $response = OpenAI::responses()->create([
                    'model' => $model,
                    'instructions' => $instructions,
                    'input' => $userInput,
                ]);
                $rawOutput = trim((string) ($response->outputText ?? ''));
            } catch (\Throwable $respException) {
                // Fallback to chat completion if responses API fails
                $response = OpenAI::chat()->create([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $instructions],
                        ['role' => 'user', 'content' => $userInput],
                    ],
                ]);
                $rawOutput = trim((string) ($response->choices[0]->message->content ?? ''));
            }

            if (empty($rawOutput)) {
                return null;
            }

            // Clean markdown code blocks if present
            $cleanJson = preg_replace('/^```(?:json)?\s*/i', '', $rawOutput);
            $cleanJson = preg_replace('/\s*```$/', '', (string) $cleanJson);

            $parsed = json_decode((string) $cleanJson, true);

            if (is_array($parsed) && isset($parsed['is_inquiry'])) {
                return [
                    'is_inquiry' => (bool) $parsed['is_inquiry'],
                    'public_comment_reply' => (string) ($parsed['public_comment_reply'] ?? ''),
                    'private_messenger_reply' => ! empty($parsed['private_messenger_reply'])
                        ? (string) $parsed['private_messenger_reply']
                        : null,
                ];
            }

            // Heuristic fallback if JSON decoding failed
            $isInquiry = $this->isOrderIntent($commentText)
                || str_contains($commentText, '؟')
                || str_contains($commentText, '?')
                || str_contains($commentText, 'كام')
                || str_contains($commentText, 'بكام')
                || str_contains($commentText, 'سعر')
                || str_contains($commentText, 'توصيل')
                || str_contains($commentText, 'عنوان');

            return [
                'is_inquiry' => $isInquiry,
                'public_comment_reply' => $isInquiry
                    ? "أهلاً بك يا {$senderName}! تم الرد على الخاص بالتفاصيل، تفقد رسائلك 😊"
                    : "شكراً جزيلاً لك يا {$senderName}! يسعدنا تواصلك دائماً ❤️",
                'private_messenger_reply' => $isInquiry ? (string) $cleanJson : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[FB_COMMENTS] OpenAI getFacebookCommentAiDecision exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Verify Messenger webhook challenge from Meta.
     * Meta sends the page's verify_token; we look it up in messenger_accounts table.
     */
    private function messengerVerify(Request $request): Response
    {
        $mode = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        $appVerifyToken = config('services.meta.messenger_verify_token')
            ?: config('services.meta.verify_token');

        Log::info('Messenger webhook verify attempt', [
            'hub_mode' => $mode,
            'token_match_app' => $appVerifyToken && hash_equals((string) $appVerifyToken, (string) $token),
            'ip' => $request->ip(),
        ]);

        if ($mode === 'subscribe' && $token) {
            // 1. Verify against App-level verify token from .env (used during Meta Dashboard setup)
            if ($appVerifyToken && hash_equals((string) $appVerifyToken, (string) $token)) {
                Log::info('Messenger webhook verified successfully via app verify token.');

                return response((string) $challenge, Response::HTTP_OK)
                    ->header('Content-Type', 'text/plain');
            }

            // 2. Verify against per-page verify token stored in messenger_accounts table
            $account = MessengerAccount::where('verify_token', $token)->first();

            if ($account) {
                Log::info('Messenger webhook verified successfully via page verify token.', ['page_id' => $account->page_id]);

                return response((string) $challenge, Response::HTTP_OK)
                    ->header('Content-Type', 'text/plain');
            }
        }

        Log::warning('Messenger webhook verification failed: token not found or wrong mode.', [
            'hub_mode' => $mode,
        ]);

        return response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    /**
     * Send a text message via Facebook Messenger Send API.
     * Returns true only when the API responds with a success status.
     */
    private function sendMessengerMessage(
        string $pageAccessToken,
        string $recipientId,
        string $text,
    ): bool {
        $response = Http::withToken($pageAccessToken)
            ->post(self::GRAPH_API_BASE.'/me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $text],
                'messaging_type' => 'RESPONSE',
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('Messenger API send error', [
            'recipient_id' => $recipientId,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Instagram Webhook
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Main Instagram webhook entry point.
     * GET  → verify Meta webhook challenge (per-item verify_token stored in DB or .env)
     * POST → handle incoming Instagram messages and send AI replies
     */
    public function instagram_web_hook(Request $request): Response|JsonResponse
    {
        Log::channel('stack')->info('[INSTAGRAM] Incoming webhook request', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'payload' => $request->all(),
        ]);
        if ($request->isMethod('get')) {
            return $this->instagramVerify($request);
        }

        // ── Log every incoming POST immediately to both stack and dedicated log
        $this->logInstagramEvent('INCOMING_POST', '⬇ Incoming webhook POST', [
            'ip' => $request->ip(),
            'payload' => $request->all(),
        ]);

        try {
            $data = $request->all();

            $object = data_get($data, 'object');

            // Only handle instagram events
            if ($object !== 'instagram') {
                $this->logInstagramEvent('IGNORED', "✗ Ignored — object is '{$object}', expected 'instagram'", [
                    'payload' => $data,
                ]);

                return response()->json(['status' => 'ignored_non_instagram'], Response::HTTP_OK);
            }

            $entry = data_get($data, 'entry.0');
            $entryId = (string) data_get($entry, 'id');
            $messagingEvent = data_get($entry, 'messaging.0');

            if (! $messagingEvent) {
                $this->logInstagramEvent('NO_MESSAGING_EVENT', '✗ No messaging event found in entry.0.messaging.0', [
                    'raw_entry' => $entry,
                ]);

                return response()->json(['status' => 'no_messaging_event'], Response::HTTP_OK);
            }

            $senderId = (string) data_get($messagingEvent, 'sender.id');
            $recipientId = (string) data_get($messagingEvent, 'recipient.id');
            $messageText = trim((string) data_get($messagingEvent, 'message.text', ''));
            $isEcho = (bool) data_get($messagingEvent, 'message.is_echo', false);
            $mid = (string) data_get($messagingEvent, 'message.mid', '');

            // ── Diagnostic inspection: identify connected accounts in DB
            $allAccounts = [];
            $senderAccount = null;
            $recipientAccount = null;
            $entryAccount = null;
            try {
                $allAccounts = InstagramItem::query()
                    ->select(['id', 'user_id', 'instagram_id', 'username', 'name', 'page_id', 'status'])
                    ->get()
                    ->toArray();

                $senderAccount = InstagramItem::where('instagram_id', $senderId)->orWhere('page_id', $senderId)->first();
                $recipientAccount = InstagramItem::where('instagram_id', $recipientId)->orWhere('page_id', $recipientId)->first();
                $entryAccount = InstagramItem::where('instagram_id', $entryId)->orWhere('page_id', $entryId)->first();
            } catch (\Throwable $dbEx) {
                Log::warning('[INSTAGRAM] Diagnostic DB inspection failed: '.$dbEx->getMessage());
            }

            $diagnostic = [
                'entry_id' => $entryId,
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'is_echo' => $isEcho,
                'message_text' => $messageText,
                'mid' => $mid,
                'sender_matched_in_db' => $senderAccount ? "YES (Item #{$senderAccount->id} @{$senderAccount->username})" : 'NOT_FOUND_IN_DB',
                'recipient_matched_in_db' => $recipientAccount ? "YES (Item #{$recipientAccount->id} @{$recipientAccount->username})" : 'NOT_FOUND_IN_DB',
                'entry_matched_in_db' => $entryAccount ? "YES (Item #{$entryAccount->id} @{$entryAccount->username})" : 'NOT_FOUND_IN_DB',
                'all_connected_accounts_in_db' => $allAccounts,
            ];

            $this->logInstagramEvent('INSPECT', '🔍 Webhook event inspection & account matching', $diagnostic);

            $senderName = 'Instagram User';

            // Check echoed messages
            if ($isEcho) {
                // If the message was sent OUTBOUND by our own bot account in the DB, ignore it to prevent loops
                if ($senderAccount && $senderAccount->status === 'active') {
                    $this->logInstagramEvent('ECHO_IGNORED', "✓ Echo ignored — message was sent OUTBOUND by the bot account #{$senderAccount->id} (@{$senderAccount->username}) to recipient {$recipientId}", [
                        'sender_id' => $senderId,
                        'recipient_id' => $recipientId,
                        'entry_id' => $entryId,
                        'is_echo' => true,
                        'text' => $messageText,
                    ]);

                    return response()->json(['status' => 'echo_ignored', 'diagnostic' => $diagnostic], Response::HTTP_OK);
                }

                // If is_echo is true BUT the sender is NOT one of our bot accounts:
                // Meta sends echo events for tester/admin accounts when they send a DM to our business page.
                // We resolve the target InstagramItem and fetch the real customer IGSID from Meta Graph API.
                $activeItems = InstagramItem::where('status', 'active')->whereNotNull('access_token')->get();
                $resolvedFromGraph = false;
                $senderIdB64 = ! empty($senderId) ? (string) base64_encode($senderId) : '';

                foreach ($activeItems as $candidateItem) {
                    $midsToTry = [];
                    if (! empty($mid)) {
                        $midsToTry[] = $mid;
                        if (! empty($senderIdB64) && ! empty($candidateItem->instagram_id)) {
                            $botIdB64 = (string) base64_encode((string) $candidateItem->instagram_id);
                            if (str_contains($mid, $senderIdB64)) {
                                $midsToTry[] = str_replace($senderIdB64, $botIdB64, $mid);
                            }
                        }
                    }

                    // 1. Try querying message by MID (both original and swapped)
                    foreach ($midsToTry as $mIdToTry) {
                        try {
                            $msgResponse = Http::timeout(5)->get(self::GRAPH_API_BASE."/{$mIdToTry}", [
                                'fields' => 'id,from,to,message',
                                'access_token' => $candidateItem->access_token,
                            ]);

                            if ($msgResponse->successful()) {
                                $fromId = (string) $msgResponse->json('from.id');
                                $fromUsername = (string) ($msgResponse->json('from.username') ?? '');
                                $fetchedText = (string) ($msgResponse->json('message') ?? $messageText);

                                // Verify that this message was NOT sent by the bot account itself
                                if (! empty($fromId) && $fromId !== (string) $candidateItem->instagram_id) {
                                    $senderId = $fromId;
                                    if (! empty($fromUsername)) {
                                        $senderName = $fromUsername;
                                    }
                                    if (! empty($fetchedText)) {
                                        $messageText = $fetchedText;
                                    }
                                    $instagramItem = $candidateItem;
                                    $targetInstagramId = (string) $candidateItem->instagram_id;
                                    $resolvedFromGraph = true;

                                    $this->logInstagramEvent('INBOUND_RESOLVED', "✓ Resolved customer message from tester echo event for @{$candidateItem->username} from @{$senderName} ({$fromId})", [
                                        'sender_id' => $senderId,
                                        'target_account' => $candidateItem->username,
                                        'message_text' => $messageText,
                                        'mid' => $mIdToTry,
                                    ]);
                                    break 2;
                                }
                            }
                        } catch (\Throwable $ex) {
                            Log::warning('[INSTAGRAM] Failed to query message details from Graph API: '.$ex->getMessage());
                        }
                    }

                    // 2. Fallback: query recent conversations on this page
                    if (! empty($candidateItem->page_id)) {
                        try {
                            $convResponse = Http::timeout(5)->get(self::GRAPH_API_BASE."/{$candidateItem->page_id}/conversations", [
                                'platform' => 'instagram',
                                'limit' => 3,
                                'fields' => 'participants,messages.limit(1){message,from,to}',
                                'access_token' => $candidateItem->access_token,
                            ]);

                            if ($convResponse->successful()) {
                                $convs = $convResponse->json('data', []);
                                foreach ($convs as $conv) {
                                    $latestMsg = data_get($conv, 'messages.data.0');
                                    if ($latestMsg) {
                                        $fromId = (string) data_get($latestMsg, 'from.id');
                                        $fromUsername = (string) data_get($latestMsg, 'from.username');
                                        $convText = (string) data_get($latestMsg, 'message');

                                        if (! empty($fromId) && $fromId !== (string) $candidateItem->instagram_id && $convText === $messageText) {
                                            $senderId = $fromId;
                                            if (! empty($fromUsername)) {
                                                $senderName = $fromUsername;
                                            }
                                            $messageText = $convText;
                                            $instagramItem = $candidateItem;
                                            $targetInstagramId = (string) $candidateItem->instagram_id;
                                            $resolvedFromGraph = true;

                                            $this->logInstagramEvent('INBOUND_RESOLVED', "✓ Resolved customer message from conversation lookup for @{$candidateItem->username} from @{$senderName} ({$fromId})", [
                                                'sender_id' => $senderId,
                                                'target_account' => $candidateItem->username,
                                                'message_text' => $messageText,
                                            ]);
                                            break 2;
                                        }
                                    }
                                }
                            }
                        } catch (\Throwable $ex) {
                            Log::warning('[INSTAGRAM] Conversation fallback query failed: '.$ex->getMessage());
                        }
                    }
                }

                if (! $resolvedFromGraph) {
                    $this->logInstagramEvent('ECHO_IGNORED', "✓ Echo ignored — message originated from external account {$senderId}", [
                        'sender_id' => $senderId,
                        'recipient_id' => $recipientId,
                        'entry_id' => $entryId,
                        'text' => $messageText,
                    ]);

                    return response()->json(['status' => 'echo_ignored', 'diagnostic' => $diagnostic], Response::HTTP_OK);
                }
            }

            $targetInstagramId = $recipientId ?: $entryId;

            $this->logInstagramEvent('MESSAGE_EVENT', '✓ Customer message event received', [
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'entry_id' => $entryId,
                'target_instagram_id' => $targetInstagramId,
                'message_text' => $messageText,
                'mid' => $mid,
            ]);

            if (! isset($instagramItem) || ! $instagramItem) {
                // Handle Meta test button from Developer Dashboard (sends dummy ID 0)
                if ($targetInstagramId === '0' || $entryId === '0') {
                    $instagramItem = InstagramItem::where('status', 'active')->first();
                } else {
                    // Resolve InstagramItem via instagram_id or page_id
                    /** @var InstagramItem|null $instagramItem */
                    $instagramItem = InstagramItem::where('instagram_id', $targetInstagramId)
                        ->where('status', 'active')
                        ->first();
                }

                if (! $instagramItem && $entryId) {
                    $instagramItem = InstagramItem::where('instagram_id', $entryId)
                        ->where('status', 'active')
                        ->first();
                }

                if (! $instagramItem) {
                    $instagramItem = InstagramItem::where(function ($q) use ($targetInstagramId, $entryId) {
                        $q->where('page_id', $targetInstagramId)
                            ->orWhere('page_id', $entryId);
                    })->where('status', 'active')->first();
                }

                // Fallback: if only one active Instagram account exists in the database, use it as fallback
                if (! $instagramItem && InstagramItem::where('status', 'active')->count() === 1) {
                    $fallbackItem = InstagramItem::where('status', 'active')->first();
                    $this->logInstagramEvent('FALLBACK_ACCOUNT', "Using single active InstagramItem #{$fallbackItem->id} (@{$fallbackItem->username}) as fallback for target {$targetInstagramId}");
                    $instagramItem = $fallbackItem;
                }
            }

            if (! $instagramItem) {
                $disabledItem = InstagramItem::where('instagram_id', $targetInstagramId)
                    ->orWhere('instagram_id', $entryId)
                    ->orWhere('page_id', $targetInstagramId)
                    ->orWhere('page_id', $entryId)
                    ->first();

                $this->logInstagramEvent('ACCOUNT_NOT_FOUND', '✗ Instagram account not found or disabled in DB', [
                    'target_id' => $targetInstagramId,
                    'entry_id' => $entryId,
                    'exists_in_db' => (bool) $disabledItem,
                    'disabled_status' => $disabledItem?->status,
                ]);

                return response()->json(['status' => 'account_not_found'], Response::HTTP_OK);
            }

            /** @var User $restaurant */
            $restaurant = $instagramItem->user;

            $this->logInstagramEvent('ACCOUNT_RESOLVED', "✓ Resolved InstagramItem #{$instagramItem->id} (@{$instagramItem->username}) for restaurant #{$restaurant->id}", [
                'msg_number' => $instagramItem->msg_number,
                'has_active_subscription' => $instagramItem->hasActiveSubscription(),
            ]);

            // Ignore non-text messages
            if (empty($messageText)) {
                $this->logInstagramEvent('NON_TEXT_IGNORED', '✗ Ignored — non-text message (reaction, image, read receipt, etc.)');

                return response()->json(['status' => 'non_text_ignored'], Response::HTTP_OK);
            }

            // Check Instagram-specific message limit
            if (! $instagramItem->hasActiveSubscription()) {
                $this->logInstagramEvent('QUOTA_EXCEEDED', "✗ Limit exceeded or inactive subscription for InstagramItem #{$instagramItem->id} (restaurant #{$restaurant->id})", [
                    'available_msgs' => $instagramItem->msg_number,
                ]);

                try {
                    $fallbackReply = 'أهلاً بك! شكراً لتواصلك معنا. فريق خدمة العملاء سيتواصل معك في أقرب وقت للرد على استفسارك بالتفصيل ومساعدتك. يسعدنا دائماً خدمتك!';
                    $this->sendInstagramMessage(
                        accessToken: $instagramItem->access_token,
                        recipientId: $senderId,
                        text: $fallbackReply,
                    );
                } catch (\Throwable $fallbackException) {
                    Log::warning('[INSTAGRAM] Fallback send failed: '.$fallbackException->getMessage());
                }

                return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
            }

            // Save incoming customer message
            $newChat = Chat::create([
                'user_id' => $restaurant->id,
                'instagram_item_id' => $instagramItem->id,
                'name' => $senderName ?? 'Instagram User',
                'phone' => null,
                'message' => $messageText,
                'is_image' => false,
                'is_admin' => false,
                'sender_type' => 'customer',
                'is_read' => false,
                'channel' => 'instagram',
                'instagram_sender_id' => $senderId,
                'meta_message_id' => data_get($messagingEvent, 'message.mid'),
            ]);

            // Broadcast to admin dashboard
            try {
                $chatData = $newChat->toArray();
                $chatData['instagram_id'] = $instagramItem->instagram_id;
                InstagramEvent::dispatch($chatData);
            } catch (\Throwable $broadcastException) {
                Log::warning('[INSTAGRAM] ⚠ InstagramEvent broadcast failed (non-fatal): '.$broadcastException->getMessage());
            }

            // Broadcast typing indicator to admin dashboard
            try {
                TypingEvent::dispatch(
                    channel: 'instagram',
                    phone: null,
                    senderId: $senderId,
                    pageId: $instagramItem->instagram_id,
                    isTyping: true,
                );
            } catch (\Throwable $broadcastException) {
                Log::warning('[INSTAGRAM] ⚠ TypingEvent broadcast failed (non-fatal): '.$broadcastException->getMessage());
            }

            // Mark seen on Instagram
            $this->showInstagramTyping($instagramItem->access_token, $senderId);

            // Get AI reply
            $this->logInstagramEvent('CALLING_AI', '⏳ Calling OpenAI for reply...');
            $reply = $this->getInstagramAiReplyWithTyping(
                instagramItem: $instagramItem,
                userMessage: $messageText,
                senderId: $senderId,
            );

            if (! $reply) {
                $reply = 'أهلاً بك! شكراً لتواصلك معنا. فريق خدمة العملاء سيتواصل معك في أقرب وقت للرد على استفسارك بالتفصيل ومساعدتك. يسعدنا دائماً خدمتك!';
                $this->logInstagramEvent('AI_FALLBACK_USED', "AI empty or failed for restaurant #{$restaurant->id}, using customer service fallback message");
            }

            $this->logInstagramEvent('AI_REPLIED', '✓ OpenAI replied', [
                'reply_preview' => mb_substr($reply, 0, 100),
            ]);

            // Send reply via Instagram Send API
            $sent = $this->sendInstagramMessage(
                accessToken: $instagramItem->access_token,
                recipientId: $senderId,
                text: $reply,
            );

            if ($sent) {
                if ((int) $instagramItem->msg_number > 0) {
                    $instagramItem->decrement('msg_number');
                }

                Chat::create([
                    'user_id' => $restaurant->id,
                    'instagram_item_id' => $instagramItem->id,
                    'name' => 'Instagram User',
                    'phone' => null,
                    'message' => $reply,
                    'is_image' => false,
                    'is_admin' => true,
                    'sender_type' => 'bot',
                    'is_read' => true,
                    'channel' => 'instagram',
                    'instagram_sender_id' => $senderId,
                ]);

                MsgSend::create([
                    'user_id' => $restaurant->id,
                    'instagram_item_id' => $instagramItem->id,
                    'whats_item_id' => null,
                    'messenger_account_id' => null,
                    'channel' => 'instagram',
                ]);

                $this->logInstagramEvent('SEND_SUCCESS', '✅ Full flow complete — reply saved & sent', [
                    'recipient_id' => $senderId,
                ]);
            } else {
                $this->logInstagramEvent('SEND_FAILED', "✗ Instagram API send FAILED for restaurant #{$restaurant->id} → Sender {$senderId}");
            }

            return response()->json([
                'status' => 'success',
                'reply' => $reply,
                'instagram_sent' => $sent,
            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            $this->logInstagramEvent('EXCEPTION', '💥 EXCEPTION: '.$e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->implode("\n"),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], Response::HTTP_OK);
        }
    }

    /**
     * Dedicated logger for Instagram Webhook events.
     * Writes to storage/logs/instagram_webhook.log AND Log::channel('stack').
     */
    private function logInstagramEvent(string $type, string $message, array $context = []): void
    {
        $logPath = storage_path('logs/instagram_webhook.log');
        $contextString = ! empty($context) ? ' | '.json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line = sprintf("[%s] [%s] %s%s\n", now()->format('Y-m-d H:i:s'), strtoupper($type), $message, $contextString);

        try {
            if (! is_dir(dirname($logPath))) {
                mkdir(dirname($logPath), 0755, true);
            }
            file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Ignore file system errors
        }

        match (strtoupper($type)) {
            'ERROR', 'EXCEPTION', 'SEND_FAILED', 'SEND_ERROR' => Log::channel('stack')->error("[INSTAGRAM] {$message}", $context),
            'WARNING', 'QUOTA_EXCEEDED', 'ACCOUNT_NOT_FOUND', 'IGNORED', 'AI_FAILED' => Log::channel('stack')->warning("[INSTAGRAM] {$message}", $context),
            default => Log::channel('stack')->info("[INSTAGRAM] {$message}", $context),
        };
    }

    /**
     * View recent Instagram webhook logs & connected accounts.
     */
    public function instagram_webhook_logs(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('lines', 100), 500);
        $logPath = storage_path('logs/instagram_webhook.log');
        $laravelLogPath = storage_path('logs/laravel.log');

        $dedicatedLogs = [];
        if (file_exists($logPath)) {
            $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $dedicatedLogs = array_values(array_slice($lines, -$limit));
        }

        $laravelLogs = [];
        if (file_exists($laravelLogPath)) {
            $allLines = file($laravelLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $igLines = array_filter($allLines, fn ($l) => str_contains($l, '[INSTAGRAM]') || str_contains($l, 'Instagram'));
            $laravelLogs = array_values(array_slice($igLines, -$limit));
        }

        $accounts = InstagramItem::all([
            'id', 'user_id', 'username', 'name', 'instagram_id', 'page_id', 'status', 'msg_number', 'updated_at',
        ]);

        return response()->json([
            'status' => true,
            'server_time' => now()->toIso8601String(),
            'dedicated_log_path' => $logPath,
            'dedicated_logs_count' => count($dedicatedLogs),
            'dedicated_logs' => array_reverse($dedicatedLogs),
            'laravel_logs_count' => count($laravelLogs),
            'laravel_logs' => array_reverse($laravelLogs),
            'instagram_accounts' => $accounts,
        ]);
    }

    /**
     * Test sending an Instagram message using Graph API directly.
     */
    public function instagram_test_send(Request $request): JsonResponse
    {
        $recipientId = (string) $request->input('recipient_id');
        $message = (string) $request->input('message', 'Test message from Smartego Backend');
        $itemId = $request->input('instagram_item_id');

        $item = $itemId ? InstagramItem::find($itemId) : InstagramItem::where('status', 'active')->first();

        if (! $item) {
            return response()->json(['status' => false, 'message' => 'No active InstagramItem found in database.'], Response::HTTP_NOT_FOUND);
        }

        if (empty($recipientId)) {
            return response()->json(['status' => false, 'message' => 'recipient_id is required (must be a numeric Instagram-Scoped User ID).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = Http::withToken($item->access_token)
            ->post(self::GRAPH_API_BASE.'/me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ]);

        $this->logInstagramEvent('TEST_SEND', "Manual test send to {$recipientId}", [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return response()->json([
            'status' => $response->successful(),
            'http_code' => $response->status(),
            'meta_response' => $response->json(),
            'used_account' => [
                'id' => $item->id,
                'username' => $item->username,
                'instagram_id' => $item->instagram_id,
            ],
        ], $response->successful() ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Subscribe an Instagram Item's connected Facebook Page to the Meta App's webhooks.
     * GET /api/instagram-webhook/subscribe-page?id=1
     */
    public function instagram_subscribe_page(Request $request): JsonResponse
    {
        $itemId = $request->input('id');
        $item = $itemId ? InstagramItem::find($itemId) : InstagramItem::where('status', 'active')->first();

        if (! $item) {
            return response()->json([
                'status' => false,
                'message' => 'No active InstagramItem found in database.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (empty($item->page_id)) {
            return response()->json([
                'status' => false,
                'message' => 'InstagramItem does not have a page_id connected.',
                'item' => [
                    'id' => $item->id,
                    'username' => $item->username,
                    'instagram_id' => $item->instagram_id,
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (empty($item->access_token)) {
            return response()->json([
                'status' => false,
                'message' => 'InstagramItem does not have an access_token.',
                'item' => [
                    'id' => $item->id,
                    'username' => $item->username,
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $graphVersion = config('services.meta.graph_version', 'v21.0');

        // 1. Check linked Instagram Business Account from Meta Graph API
        $pageDetailsResponse = Http::get(
            "https://graph.facebook.com/{$graphVersion}/{$item->page_id}",
            [
                'fields' => 'id,name,instagram_business_account{id,username,name}',
                'access_token' => $item->access_token,
            ]
        );

        // 2. Post to subscribed_apps (using valid Meta Page fields: messages, messaging_postbacks, message_reads)
        $subscribeResponse = Http::post(
            "https://graph.facebook.com/{$graphVersion}/{$item->page_id}/subscribed_apps",
            [
                'subscribed_fields' => 'messages,messaging_postbacks,message_reads',
                'access_token' => $item->access_token,
            ]
        );

        // 3. Query subscribed_apps to verify status
        $verifyResponse = Http::get(
            "https://graph.facebook.com/{$graphVersion}/{$item->page_id}/subscribed_apps",
            [
                'access_token' => $item->access_token,
            ]
        );

        $this->logInstagramEvent('PAGE_SUBSCRIBED', "Subscribed page {$item->page_id} to Instagram webhooks", [
            'item_id' => $item->id,
            'username' => $item->username,
            'page_id' => $item->page_id,
            'page_details' => $pageDetailsResponse->json(),
            'subscribe_response' => $subscribeResponse->json(),
            'verify_response' => $verifyResponse->json(),
        ]);

        return response()->json([
            'status' => $subscribeResponse->successful(),
            'meta_page_details' => $pageDetailsResponse->json(),
            'subscribe_result' => $subscribeResponse->json(),
            'current_subscriptions' => $verifyResponse->json(),
            'database_account' => [
                'id' => $item->id,
                'username' => $item->username,
                'instagram_id' => $item->instagram_id,
                'page_id' => $item->page_id,
            ],
        ], $subscribeResponse->successful() ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    /**
     * Update the instagram_id of an active InstagramItem in the database.
     * GET /api/instagram-webhook/set-active-id?instagram_id=17841401051588301&id=1
     */
    public function instagram_set_active_id(Request $request): JsonResponse
    {
        $newIgId = $request->input('instagram_id');
        $status = $request->input('status');
        $itemId = $request->input('id');
        $action = $request->input('action');

        $item = $itemId ? InstagramItem::find($itemId) : InstagramItem::where('status', 'active')->first();

        if (! $item) {
            return response()->json(['status' => false, 'message' => 'InstagramItem not found in database.'], Response::HTTP_NOT_FOUND);
        }

        if ($action === 'delete') {
            $desc = "#{$item->id} (@{$item->username})";
            $item->delete();
            $this->logInstagramEvent('ACCOUNT_DELETED', "Deleted InstagramItem {$desc}");

            return response()->json(['status' => true, 'message' => "Successfully deleted InstagramItem {$desc}."]);
        }

        $updates = [];
        if (! empty($newIgId)) {
            $updates['instagram_id'] = (string) $newIgId;
        }
        if (! empty($status)) {
            $updates['status'] = (string) $status;
        }

        if (empty($updates)) {
            return response()->json(['status' => false, 'message' => 'No fields to update provided (pass instagram_id or status).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $item->update($updates);
        $this->logInstagramEvent('ACCOUNT_UPDATED', "Updated InstagramItem #{$item->id} (@{$item->username})", $updates);

        return response()->json([
            'status' => true,
            'message' => "Successfully updated InstagramItem #{$item->id} (@{$item->username}).",
            'account' => [
                'id' => $item->id,
                'username' => $item->username,
                'instagram_id' => $item->fresh()->instagram_id,
                'page_id' => $item->page_id,
                'status' => $item->fresh()->status,
            ],
        ]);
    }

    /**
     * Verify Instagram webhook challenge from Meta.
     */
    private function instagramVerify(Request $request): Response
    {
        $mode = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        $appVerifyToken = config('services.meta.instagram_verify_token')
            ?: (config('services.meta.messenger_verify_token') ?: config('services.meta.verify_token'));

        $this->logInstagramEvent('VERIFY_ATTEMPT', 'Instagram webhook verify attempt', [
            'hub_mode' => $mode,
            'token_match_app' => $appVerifyToken && hash_equals((string) $appVerifyToken, (string) $token),
            'ip' => $request->ip(),
        ]);

        if ($mode === 'subscribe' && $token) {
            if ($appVerifyToken && hash_equals((string) $appVerifyToken, (string) $token)) {
                $this->logInstagramEvent('VERIFY_SUCCESS', 'Verified successfully via app verify token.');

                return response((string) $challenge, Response::HTTP_OK)
                    ->header('Content-Type', 'text/plain');
            }

            $item = InstagramItem::where('verify_token', $token)->first();

            if ($item) {
                $this->logInstagramEvent('VERIFY_SUCCESS', 'Verified successfully via item verify token.', ['instagram_id' => $item->instagram_id]);

                return response((string) $challenge, Response::HTTP_OK)
                    ->header('Content-Type', 'text/plain');
            }
        }

        $this->logInstagramEvent('VERIFY_FAIL', 'Instagram webhook verification failed: token not found or wrong mode.', [
            'hub_mode' => $mode,
        ]);

        return response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    /**
     * Send a text message via Instagram Send API.
     */
    private function sendInstagramMessage(
        string $accessToken,
        string $recipientId,
        string $text,
    ): bool {
        $response = Http::withToken($accessToken)
            ->post(self::GRAPH_API_BASE.'/me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $text],
            ]);

        if ($response->successful()) {
            $this->logInstagramEvent('SEND_SUCCESS', "Message sent via Graph API to {$recipientId}", [
                'meta_response' => $response->json(),
            ]);

            return true;
        }

        $this->logInstagramEvent('SEND_ERROR', "Instagram API send error to {$recipientId}", [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return false;
    }

    /**
     * Send mark_seen and typing_on sender actions for Instagram.
     * Displays the 3-dot typing indicator in Instagram DM while AI processes the response.
     */
    private function showInstagramTyping(
        string $accessToken,
        string $recipientId,
    ): void {
        $endpoint = self::GRAPH_API_BASE.'/me/messages';

        try {
            // 1. Mark incoming message as seen
            Http::withToken($accessToken)
                ->timeout(5)
                ->post($endpoint, [
                    'recipient' => ['id' => $recipientId],
                    'sender_action' => 'mark_seen',
                ]);

            // 2. Turn on typing indicator dots
            $response = Http::withToken($accessToken)
                ->timeout(5)
                ->post($endpoint, [
                    'recipient' => ['id' => $recipientId],
                    'sender_action' => 'typing_on',
                ]);

            if ($response->successful()) {
                $this->logInstagramEvent('TYPING_ON', "✓ typing_on indicator sent to {$recipientId}");
            } else {
                Log::channel('stack')->warning('[INSTAGRAM] ✗ typing_on failed', [
                    'recipient_id' => $recipientId,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('stack')->warning('[INSTAGRAM] ⚠ showInstagramTyping exception: '.$e->getMessage());
        }
    }

    /**
     * Call getInstagramAiReply with typing management.
     */
    private function getInstagramAiReplyWithTyping(
        InstagramItem $instagramItem,
        string $userMessage,
        string $senderId,
    ): ?string {
        $this->showInstagramTyping($instagramItem->access_token, $senderId);

        return $this->getInstagramAiReply($instagramItem, $userMessage);
    }

    /**
     * Get an AI-generated reply for Instagram using the InstagramItem's ai_context and ai_file.
     */
    private function getInstagramAiReply(InstagramItem $instagramItem, string $userMessage): ?string
    {
        $aiContext = ! empty($instagramItem->ai_context)
            ? $instagramItem->ai_context
            : (Setting::firstWhere('name', 'ai_context')?->value ?? 'أنت موظف خدمة عملاء، ردّ بأسلوب ودي وبسيط.');

        $fileContent = $this->resolveAiFileContent($instagramItem->ai_file);

        $fileDataSection = '';
        if (! empty($fileContent)) {
            $fileDataSection = "\n\nبيانات وقائمة المنتجات / الخدمات والمعلومات المتاحة:\n".$fileContent;
        }

        $linksSection = '';
        if ($instagramItem->android_link || $instagramItem->ios_link || $instagramItem->website_url) {
            $linksSection = "\n\nروابط وتفاصيل الطلب المتاحة:";
            if ($instagramItem->website_url) {
                $linksSection .= "\n- الموقع الإلكتروني: {$instagramItem->website_url}";
            }
            if ($instagramItem->android_link) {
                $linksSection .= "\n- تطبيق أندرويد (Android): {$instagramItem->android_link}";
            }
            if ($instagramItem->ios_link) {
                $linksSection .= "\n- تطبيق آيفون (iOS): {$instagramItem->ios_link}";
            }
        }

        $instructions = <<<PROMPT
        {$aiContext}
        {$fileDataSection}
        {$linksSection}

        التعليمات:
        - الرد باللغة العربية فقط بأسلوب مهذب ومساعد وموجز ومحترم.
        - اعتمد على البيانات المذكورة أعلاه في الرد على استفسارات العميل ولا تخترع أي معلومات أو أسعار غير موجودة.
        - إذا سأل العميل عن شيء غير مذكور في البيانات أو غير متاح، أخبره بلباقة أنه غير متوفر حالياً.
        - أول ما يطلب العميل (عندما يريد طلب، يسأل كيف يطلب، يريد عمل أوردر، أو يطلب أي صنف أو وجبة): يجب الرد عليه بأسلوب مهذب ومحترم وإخباره: «تقدر تطلب من هنا» مع إرسال روابط الطلب المتوفرة (الموقع الإلكتروني، تطبيق أندرويد، وتطبيق iOS) المذكورة أعلاه.
        - في حال عدم توفر روابط طلب أعلاه، أخبر العميل بلباقة أنه يمكنه كتابة طلبه وتفاصيله هنا لمساعدته.
        PROMPT;

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $response = OpenAI::responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'input' => $userMessage,
            ]);

            $reply = trim((string) ($response->outputText ?? ''));

            if (! empty($reply) && $this->isOrderIntent($userMessage)) {
                $hasLink = ($instagramItem->website_url && str_contains($reply, $instagramItem->website_url))
                    || ($instagramItem->android_link && str_contains($reply, $instagramItem->android_link))
                    || ($instagramItem->ios_link && str_contains($reply, $instagramItem->ios_link));

                if (! $hasLink) {
                    $orderLinksMsg = $this->formatOrderingLinksMessage($instagramItem->website_url, $instagramItem->android_link, $instagramItem->ios_link);
                    if ($orderLinksMsg) {
                        $reply .= "\n\n{$orderLinksMsg}";
                    }
                }
            }

            return $reply ?: null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI getInstagramAiReply fallback triggered: '.$e->getMessage());

            $fallback = 'أهلاً بك! شكراً لتواصلك معنا. فريق خدمة العملاء سيتواصل معك في أقرب وقت للرد على استفسارك بالتفصيل ومساعدتك. يسعدنا دائماً خدمتك!';
            $orderLinksMsg = $this->formatOrderingLinksMessage($instagramItem->website_url, $instagramItem->android_link, $instagramItem->ios_link);
            if ($orderLinksMsg) {
                $fallback .= "\n\n{$orderLinksMsg}";
            }

            return $fallback;
        }
    }

    /**
     * Get an AI-generated reply for WhatsApp using the WhatsItem's ai_context and ai_file.
     * Uses the ai_file contents directly instead of App\Models\Food.
     */
    private function getAiReply(User $restaurant, string $userMessage, ?WhatsItem $whatsItem = null): ?string
    {
        // 1. ai_context: use WhatsItem ai_context if set, otherwise fallback to Setting
        $aiContext = ! empty($whatsItem?->ai_context)
            ? $whatsItem->ai_context
            : (Setting::firstWhere('name', 'ai_context')?->value ?? 'أنت موظف خدمة عملاء لمطعم، ردّ بأسلوب ودي وبسيط.');

        // 2. ai_file: if present on WhatsItem, resolve its contents; if not found, don't use it
        $fileDataSection = '';
        if (! empty($whatsItem?->ai_file)) {
            $fileContent = $this->resolveAiFileContent($whatsItem->ai_file);
            if (! empty($fileContent)) {
                $fileDataSection = "\n\nبيانات وقائمة المنتجات / الخدمات والمعلومات المتاحة:\n".$fileContent;
            }
        }

        // 3. App links and website
        $linksSection = '';
        if ($whatsItem && ($whatsItem->android_link || $whatsItem->ios_link || $whatsItem->website_url)) {
            $linksSection = "\n\nروابط وتفاصيل الطلب المتاحة:";
            if ($whatsItem->website_url) {
                $linksSection .= "\n- الموقع الإلكتروني: {$whatsItem->website_url}";
            }
            if ($whatsItem->android_link) {
                $linksSection .= "\n- تطبيق أندرويد (Android): {$whatsItem->android_link}";
            }
            if ($whatsItem->ios_link) {
                $linksSection .= "\n- تطبيق آيفون (iOS): {$whatsItem->ios_link}";
            }
        }

        $instructions = <<<PROMPT
        {$aiContext}
        {$fileDataSection}
        {$linksSection}

        التعليمات:
        - الرد باللغة العربية فقط بأسلوب مهذب ومساعد وموجز ومحترم.
        - اعتمد على البيانات المذكورة أعلاه في الرد على استفسارات العميل ولا تخترع أي معلومات أو أسعار غير موجودة.
        - إذا سأل العميل عن شيء غير مذكور في البيانات أو غير متاح، أخبره بلباقة أنه غير متوفر حالياً.
        - أول ما يطلب العميل (عندما يريد طلب، يسأل كيف يطلب، يريد عمل أوردر، أو يطلب أي صنف أو وجبة): يجب الرد عليه بأسلوب مهذب ومحترم وإخباره: «تقدر تطلب من هنا» مع إرسال روابط الطلب المتوفرة (الموقع الإلكتروني، تطبيق أندرويد، وتطبيق iOS) المذكورة أعلاه.
        - في حال عدم توفر روابط طلب أعلاه، أخبر العميل بلباقة أنه يمكنه كتابة طلبه وتفاصيله هنا لمساعدته.
        PROMPT;

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $response = OpenAI::responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'input' => $userMessage,
            ]);

            $reply = trim((string) ($response->outputText ?? ''));

            if (! empty($reply) && $this->isOrderIntent($userMessage) && $whatsItem) {
                $hasLink = ($whatsItem->website_url && str_contains($reply, $whatsItem->website_url))
                    || ($whatsItem->android_link && str_contains($reply, $whatsItem->android_link))
                    || ($whatsItem->ios_link && str_contains($reply, $whatsItem->ios_link));

                if (! $hasLink) {
                    $orderLinksMsg = $this->formatOrderingLinksMessage($whatsItem->website_url, $whatsItem->android_link, $whatsItem->ios_link);
                    if ($orderLinksMsg) {
                        $reply .= "\n\n{$orderLinksMsg}";
                    }
                }
            }

            return $reply ?: null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI getAiReply fallback triggered: '.$e->getMessage());

            $fallback = 'أهلاً بك! نسعد بخدمتك.';
            $orderLinksMsg = $this->formatOrderingLinksMessage($whatsItem?->website_url, $whatsItem?->android_link, $whatsItem?->ios_link);
            if ($orderLinksMsg) {
                $fallback .= "\n{$orderLinksMsg}";
            } else {
                $fallback .= ' يمكنك طرح استفسارك أو طلبك مباشرة، وسنكون سعداء بمساعدتك.';
            }

            return $fallback;
        }
    }

    /**
     * Call getMessengerAiReply while keeping Messenger typing dots alive.
     *
     * Facebook's typing_on action auto-expires after ~20 seconds.
     * This method re-sends typing_on every 15 seconds using pcntl_alarm
     * so the user always sees the typing dots while the AI is thinking.
     *
     * Falls back gracefully (single typing_on) when pcntl is unavailable.
     */
    private function getMessengerAiReplyWithTyping(
        MessengerAccount $messengerAccount,
        string $userMessage,
        string $senderId,
    ): ?string {
        $pageAccessToken = $messengerAccount->page_access_token;
        $intervalSeconds = 15;

        // Send the first typing_on immediately
        $this->showMessengerTyping($pageAccessToken, $senderId);

        // ── Strategy 1: pcntl_alarm (preferred — non-blocking tick)
        if (function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
            $controller = $this; // capture for closure

            pcntl_signal(SIGALRM, function () use ($pageAccessToken, $senderId, $intervalSeconds, $controller): void {
                $controller->showMessengerTyping($pageAccessToken, $senderId);
                pcntl_alarm($intervalSeconds); // schedule the next tick
            });

            pcntl_alarm($intervalSeconds); // fire first alarm after 15s

            try {
                $reply = $this->getMessengerAiReply($messengerAccount, $userMessage);
            } finally {
                pcntl_alarm(0);                       // cancel any pending alarm
                pcntl_signal(SIGALRM, SIG_DFL);       // restore default handler
            }

            return $reply;
        }

        // ── Strategy 2: tick-based loop (when pcntl is unavailable)
        // Runs a blocking loop that periodically dispatches typing_on via
        // a registered tick function while the AI call is in-flight.
        // PHP ticks fire after every N statements (declare(ticks=1) scope).
        $lastTypingSentAt = time();
        $typingCallback = function () use ($pageAccessToken, $senderId, $intervalSeconds, &$lastTypingSentAt): void {
            if ((time() - $lastTypingSentAt) >= $intervalSeconds) {
                $this->showMessengerTyping($pageAccessToken, $senderId);
                $lastTypingSentAt = time();
            }
        };

        register_tick_function($typingCallback);

        try {
            // declare(ticks=1) only applies to the current file scope at parse
            // time, so we wrap the call inside eval with declare to activate
            // tick dispatch for the duration of the AI call.
            $reply = null;
            $messengerAccountRef = $messengerAccount;
            $userMessageRef = $userMessage;
            $selfRef = $this;

            // Activate ticks and execute the AI call within that scope.
            // Using a closure here avoids the need for eval().
            $aiCallable = static function () use ($selfRef, $messengerAccountRef, $userMessageRef): ?string {
                return $selfRef->getMessengerAiReply($messengerAccountRef, $userMessageRef);
            };

            $reply = $aiCallable();
        } finally {
            unregister_tick_function($typingCallback);
        }

        return $reply;
    }

    /**
     * Get an AI-generated reply for Facebook Messenger using the MessengerAccount's ai_context and ai_file.
     * Uses the ai_file contents directly instead of App\Models\Food.
     */
    private function getMessengerAiReply(MessengerAccount $messengerAccount, string $userMessage): ?string
    {
        $aiContext = ! empty($messengerAccount->ai_context)
            ? $messengerAccount->ai_context
            : (Setting::firstWhere('name', 'ai_context')?->value ?? 'أنت موظف خدمة عملاء، ردّ بأسلوب ودي وبسيط.');

        $restaurant = $messengerAccount->user;

        // Resolve data from ai_file instead of App\Models\Food
        $fileContent = $this->resolveAiFileContent($messengerAccount->ai_file);

        $fileDataSection = '';
        if (! empty($fileContent)) {
            $fileDataSection = "\n\nبيانات وقائمة المنتجات / الخدمات والمعلومات المتاحة:\n".$fileContent;
        }

        $linksSection = '';
        if ($messengerAccount->android_link || $messengerAccount->ios_link || $messengerAccount->website_url) {
            $linksSection = "\n\nروابط وتفاصيل الطلب المتاحة:";
            if ($messengerAccount->website_url) {
                $linksSection .= "\n- الموقع الإلكتروني: {$messengerAccount->website_url}";
            }
            if ($messengerAccount->android_link) {
                $linksSection .= "\n- تطبيق أندرويد (Android): {$messengerAccount->android_link}";
            }
            if ($messengerAccount->ios_link) {
                $linksSection .= "\n- تطبيق آيفون (iOS): {$messengerAccount->ios_link}";
            }
        }

        $instructions = <<<PROMPT
        {$aiContext}
        {$fileDataSection}
        {$linksSection}

        التعليمات:
        - الرد باللغة العربية فقط بأسلوب مهذب ومساعد وموجز ومحترم.
        - اعتمد على البيانات المذكورة أعلاه في الرد على استفسارات العميل ولا تخترع أي معلومات أو أسعار غير موجودة.
        - إذا سأل العميل عن شيء غير مذكور في البيانات أو غير متاح، أخبره بلباقة أنه غير متوفر حالياً.
        - أول ما يطلب العميل (عندما يريد طلب، يسأل كيف يطلب، يريد عمل أوردر، أو يطلب أي صنف أو وجبة): يجب الرد عليه بأسلوب مهذب ومحترم وإخباره: «تقدر تطلب من هنا» مع إرسال روابط الطلب المتوفرة (الموقع الإلكتروني، تطبيق أندرويد، وتطبيق iOS) المذكورة أعلاه.
        - في حال عدم توفر روابط طلب أعلاه، أخبر العميل بلباقة أنه يمكنه كتابة طلبه وتفاصيله هنا لمساعدته.
        PROMPT;

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $response = OpenAI::responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'input' => $userMessage,
            ]);

            $reply = trim((string) ($response->outputText ?? ''));

            if (! empty($reply) && $this->isOrderIntent($userMessage)) {
                $hasLink = ($messengerAccount->website_url && str_contains($reply, $messengerAccount->website_url))
                    || ($messengerAccount->android_link && str_contains($reply, $messengerAccount->android_link))
                    || ($messengerAccount->ios_link && str_contains($reply, $messengerAccount->ios_link));

                if (! $hasLink) {
                    $orderLinksMsg = $this->formatOrderingLinksMessage($messengerAccount->website_url, $messengerAccount->android_link, $messengerAccount->ios_link);
                    if ($orderLinksMsg) {
                        $reply .= "\n\n{$orderLinksMsg}";
                    }
                }
            }

            return $reply ?: null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI getMessengerAiReply fallback triggered: '.$e->getMessage());

            $fallback = 'أهلاً بك! نسعد بخدمتك.';
            $orderLinksMsg = $this->formatOrderingLinksMessage($messengerAccount->website_url, $messengerAccount->android_link, $messengerAccount->ios_link);
            if ($orderLinksMsg) {
                $fallback .= "\n{$orderLinksMsg}";
            } else {
                $fallback .= ' يمكنك طرح استفسارك أو طلبك مباشرة، وسنكون سعداء بمساعدتك.';
            }

            return $fallback;
        }
    }

    /**
     * Check if the customer message expresses an intent to order.
     */
    private function isOrderIntent(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        $keywords = [
            'اطلب', 'أطلب', 'طلب', 'اوردر', 'أوردر', 'order',
            'دليفري', 'توصيل', 'شراء', 'احجز', 'أحجز', 'حجز',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format a polite ordering message with the available links.
     */
    private function formatOrderingLinksMessage(?string $websiteUrl, ?string $androidLink, ?string $iosLink): ?string
    {
        if (! $websiteUrl && ! $androidLink && ! $iosLink) {
            return null;
        }

        $lines = ['تقدر تطلب من هنا:'];

        if ($websiteUrl) {
            $lines[] = "الموقع الإلكتروني: {$websiteUrl}";
        }
        if ($androidLink) {
            $lines[] = "تطبيق أندرويد: {$androidLink}";
        }
        if ($iosLink) {
            $lines[] = "تطبيق iOS: {$iosLink}";
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve and read text content from ai_file (file path, storage, URL, or raw text).
     */
    private function resolveAiFileContent(?string $aiFile): ?string
    {
        if (empty($aiFile)) {
            return null;
        }

        $trimmed = trim($aiFile);

        // If string contains newlines, treat it directly as content rather than a file path
        if (str_contains($trimmed, "\n") || str_contains($trimmed, "\r")) {
            return $trimmed;
        }

        // 1. If it's a URL
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            try {
                $response = Http::timeout(5)->get($trimmed);
                if ($response->successful()) {
                    return $response->body();
                }
            } catch (\Throwable $e) {
                Log::warning("Could not fetch ai_file from URL: {$trimmed} — ".$e->getMessage());
            }

            return null;
        }

        // 2. Safe filesystem / storage checks
        try {
            if (@file_exists($trimmed) && @is_file($trimmed)) {
                return @file_get_contents($trimmed);
            }

            $storageApp = storage_path('app/'.$trimmed);
            if (@file_exists($storageApp) && @is_file($storageApp)) {
                return @file_get_contents($storageApp);
            }

            $storagePublic = storage_path('app/public/'.$trimmed);
            if (@file_exists($storagePublic) && @is_file($storagePublic)) {
                return @file_get_contents($storagePublic);
            }

            $publicPath = public_path($trimmed);
            if (@file_exists($publicPath) && @is_file($publicPath)) {
                return @file_get_contents($publicPath);
            }

            if (Storage::exists($trimmed)) {
                return Storage::get($trimmed);
            }
        } catch (\Throwable $e) {
            Log::info("Could not resolve ai_file as path: {$trimmed}");
        }

        // 3. Fallback: treat string as direct content
        return $trimmed;
    }

    /**
     * Resolve any tool calls the AI made and return the outputs.
     *
     * @param  array<mixed>  $outputItems
     * @return array<mixed>
     */
    private function resolveToolCalls(array $outputItems, string $restaurantid): array
    {
        $toolOutputs = [];

        foreach ($outputItems as $item) {
            if (($item->type ?? null) !== 'function_call') {
                continue;
            }

            if (($item->name ?? null) === 'search_foods') {
                $args = json_decode($item->arguments ?? '{}', true) ?: [];
                $query = trim($args['query'] ?? '');
                $limit = max(1, min(10, (int) ($args['limit'] ?? 5)));

                $foodsQuery = Food::query()
                    ->where('status', 1)
                    ->where('is_out_of_stock', 0)
                    ->where(function ($q) use ($query) {
                        $q->where('name_ar', 'like', "%{$query}%")
                            ->orWhere('description_ar', 'like', "%{$query}%");
                    });

                if (Schema::hasColumn('food', 'restaurantid')) {
                    $foodsQuery->where('restaurantid', $restaurantid);
                }

                $foods = $foodsQuery
                    ->limit($limit)
                    ->get(['id', 'name_ar', 'description_ar', 'price', 'discount_type', 'discount_value'])
                    ->toArray();

                $toolOutputs[] = [
                    'type' => 'function_call_output',
                    'call_id' => $item->callId,
                    'output' => json_encode(['foods' => $foods], JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return $toolOutputs;
    }

    /**
     * Send WhatsApp typing indicator (mark as read + typing_on).
     * WhatsApp requires the message to be marked as read first.
     */
    private function sendWhatsAppTypingIndicator(
        string $accessToken,
        string $phoneNumberId,
        string $messageId,
    ): void {
        // Step 1: Mark message as read (required before showing typing)
        Http::withToken($accessToken)
            ->post(self::GRAPH_API_BASE."/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ]);

        // Step 2: Show typing indicator
        Http::withToken($accessToken)
            ->post(self::GRAPH_API_BASE."/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $messageId, // not used here — Meta uses context from read
                'type' => 'reaction',
            ]);
    }

    /**
     * Mark the incoming WhatsApp message as read (shows ✓✓ blue checkmarks).
     * NOTE: WhatsApp Cloud API does NOT support typing indicators.
     * Mark-as-read is the only available visual feedback to the customer.
     */
    private function showWhatsAppTyping(
        string $accessToken,
        string $phoneNumberId,
        string $senderPhone,
        string $incomingMessageId,
    ): void {
        try {
            Http::withToken($accessToken)
                ->post(self::GRAPH_API_BASE."/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $incomingMessageId,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp mark-as-read failed: '.$e->getMessage());
        }
    }

    /**
     * Send Messenger mark_seen and typing_on sender actions.
     * Note: mark_seen is required first so Messenger marks the incoming message read
     * and displays the typing bubble to the user.
     */
    private function showMessengerTyping(
        string $pageAccessToken,
        string $recipientId,
    ): void {
        $endpoint = self::GRAPH_API_BASE.'/me/messages';

        try {
            // 1. Mark incoming message as seen (read receipt)
            Http::withToken($pageAccessToken)
                ->post($endpoint, [
                    'recipient' => ['id' => $recipientId],
                    'sender_action' => 'mark_seen',
                ]);

            // 2. Show typing dots
            $response = Http::withToken($pageAccessToken)
                ->post($endpoint, [
                    'recipient' => ['id' => $recipientId],
                    'sender_action' => 'typing_on',
                ]);

            if (! $response->successful()) {
                Log::channel('stack')->warning('[MESSENGER] ✗ typing_on failed', [
                    'recipient_id' => $recipientId,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            } else {
                Log::channel('stack')->info('[MESSENGER] ✓ typing_on sent successfully to PSID: '.$recipientId);
            }
        } catch (\Throwable $e) {
            Log::channel('stack')->warning('[MESSENGER] ⚠ typing_on exception: '.$e->getMessage());
        }
    }

    /**
     * Send a text message via the WhatsApp Cloud API.
     * Returns true only when the API responds with a success status.
     */
    private function sendTextMessage(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $body,
    ): bool {
        // Normalize and clean phone number
        $cleanTo = preg_replace('/[^0-9]/', '', $to);

        // Convert local Egyptian number (01xxxxxxxxx) to international (201xxxxxxxxx)
        if (strlen($cleanTo) === 11 && str_starts_with($cleanTo, '01')) {
            $cleanTo = '2'.$cleanTo;
        }

        $response = Http::withToken($accessToken)
            ->post(self::GRAPH_API_BASE."/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $cleanTo,
                'type' => 'text',
                'text' => ['body' => $body],
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('WhatsApp API error', [
            'phone_number_id' => $phoneNumberId,
            'to' => $to,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return false;
    }
}
