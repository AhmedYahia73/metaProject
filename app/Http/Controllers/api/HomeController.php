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
        if ($request->isMethod('get')) {
            return $this->instagramVerify($request);
        }

        // ── Log every incoming POST immediately
        Log::channel('stack')->info('[INSTAGRAM] ⬇ Incoming POST', [
            'ip' => $request->ip(),
            'payload' => $request->all(),
        ]);

        try {
            $data = $request->all();

            $object = data_get($data, 'object');

            // Only handle instagram events
            if ($object !== 'instagram') {
                Log::channel('stack')->warning("[INSTAGRAM] ✗ Ignored — object is '{$object}', expected 'instagram'");

                return response()->json(['status' => 'ignored_non_instagram'], Response::HTTP_OK);
            }

            $entry = data_get($data, 'entry.0');
            $entryId = (string) data_get($entry, 'id');
            $messagingEvent = data_get($entry, 'messaging.0');

            if (! $messagingEvent) {
                Log::channel('stack')->warning('[INSTAGRAM] ✗ No messaging event found in entry.0.messaging.0', [
                    'raw_entry' => $entry,
                ]);

                return response()->json(['status' => 'no_messaging_event'], Response::HTTP_OK);
            }

            // Ignore echoed messages (sent by the account itself)
            if (data_get($messagingEvent, 'message.is_echo')) {
                Log::channel('stack')->info('[INSTAGRAM] ✓ Echo ignored (sent by account)');

                return response()->json(['status' => 'echo_ignored'], Response::HTTP_OK);
            }

            $senderId = (string) data_get($messagingEvent, 'sender.id');
            $recipientId = (string) data_get($messagingEvent, 'recipient.id');
            $messageText = trim((string) data_get($messagingEvent, 'message.text', ''));

            $targetInstagramId = $recipientId ?: $entryId;

            Log::channel('stack')->info('[INSTAGRAM] ✓ Message event', [
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'entry_id' => $entryId,
                'message_text' => $messageText,
                'mid' => data_get($messagingEvent, 'message.mid'),
            ]);

            // Resolve InstagramItem via instagram_id or page_id
            /** @var InstagramItem|null $instagramItem */
            $instagramItem = InstagramItem::where('instagram_id', $targetInstagramId)
                ->where('status', 'active')
                ->first();

            if (! $instagramItem && $entryId) {
                $instagramItem = InstagramItem::where('instagram_id', $entryId)
                    ->where('status', 'active')
                    ->first();
            }

            if (! $instagramItem) {
                $instagramItem = InstagramItem::where('page_id', $targetInstagramId)
                    ->orWhere('page_id', $entryId)
                    ->where('status', 'active')
                    ->first();
            }

            if (! $instagramItem) {
                $disabledItem = InstagramItem::where('instagram_id', $targetInstagramId)
                    ->orWhere('instagram_id', $entryId)
                    ->orWhere('page_id', $targetInstagramId)
                    ->orWhere('page_id', $entryId)
                    ->first();

                Log::channel('stack')->warning('[INSTAGRAM] ✗ Instagram account not found or disabled', [
                    'target_id' => $targetInstagramId,
                    'entry_id' => $entryId,
                    'exists_in_db' => (bool) $disabledItem,
                    'disabled_status' => $disabledItem?->status,
                ]);

                return response()->json(['status' => 'account_not_found'], Response::HTTP_OK);
            }

            /** @var User $restaurant */
            $restaurant = $instagramItem->user;

            // Ignore non-text messages
            if (empty($messageText)) {
                Log::channel('stack')->info('[INSTAGRAM] ✗ Ignored — non-text message');

                return response()->json(['status' => 'non_text_ignored'], Response::HTTP_OK);
            }

            // Check Instagram-specific message limit
            if (! $instagramItem->hasActiveSubscription()) {
                Log::channel('stack')->warning("[INSTAGRAM] ✗ Limit exceeded or inactive subscription for InstagramItem #{$instagramItem->id} (restaurant #{$restaurant->id})");

                return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
            }

            // Save incoming customer message
            $newChat = Chat::create([
                'user_id' => $restaurant->id,
                'instagram_item_id' => $instagramItem->id,
                'name' => 'Instagram User',
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
            Log::channel('stack')->info('[INSTAGRAM] ⏳ Calling OpenAI...');
            $reply = $this->getInstagramAiReplyWithTyping(
                instagramItem: $instagramItem,
                userMessage: $messageText,
                senderId: $senderId,
            );

            if (! $reply) {
                Log::channel('stack')->warning("[INSTAGRAM] ✗ OpenAI returned empty reply for restaurant #{$restaurant->id}");

                return response()->json(['status' => 'ai_failed'], Response::HTTP_OK);
            }

            Log::channel('stack')->info('[INSTAGRAM] ✓ OpenAI replied', [
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

                Log::channel('stack')->info('[INSTAGRAM] ✅ Full flow complete — reply saved & sent');
            } else {
                Log::channel('stack')->error("[INSTAGRAM] ✗ Instagram API send FAILED for restaurant #{$restaurant->id} → Sender {$senderId}");
            }

            return response()->json([
                'status' => 'success',
                'reply' => $reply,
                'instagram_sent' => $sent,
            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            Log::channel('stack')->error('[INSTAGRAM] 💥 EXCEPTION: '.$e->getMessage(), [
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
     * Verify Instagram webhook challenge from Meta.
     */
    private function instagramVerify(Request $request): Response
    {
        $mode = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        $appVerifyToken = config('services.meta.instagram_verify_token')
            ?: (config('services.meta.messenger_verify_token') ?: config('services.meta.verify_token'));

        Log::info('Instagram webhook verify attempt', [
            'hub_mode' => $mode,
            'token_match_app' => $appVerifyToken && hash_equals((string) $appVerifyToken, (string) $token),
            'ip' => $request->ip(),
        ]);

        if ($mode === 'subscribe' && $token) {
            if ($appVerifyToken && hash_equals((string) $appVerifyToken, (string) $token)) {
                Log::info('Instagram webhook verified successfully via app verify token.');

                return response((string) $challenge, Response::HTTP_OK)
                    ->header('Content-Type', 'text/plain');
            }

            $item = InstagramItem::where('verify_token', $token)->first();

            if ($item) {
                Log::info('Instagram webhook verified successfully via item verify token.', ['instagram_id' => $item->instagram_id]);

                return response((string) $challenge, Response::HTTP_OK)
                    ->header('Content-Type', 'text/plain');
            }
        }

        Log::warning('Instagram webhook verification failed: token not found or wrong mode.', [
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
            return true;
        }

        Log::error('Instagram API send error', [
            'recipient_id' => $recipientId,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return false;
    }

    /**
     * Send mark_seen sender action for Instagram.
     */
    private function showInstagramTyping(
        string $accessToken,
        string $recipientId,
    ): void {
        try {
            Http::withToken($accessToken)
                ->post(self::GRAPH_API_BASE.'/me/messages', [
                    'recipient' => ['id' => $recipientId],
                    'sender_action' => 'mark_seen',
                ]);
        } catch (\Throwable $e) {
            Log::channel('stack')->warning('[INSTAGRAM] ⚠ mark_seen exception: '.$e->getMessage());
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

            $fallback = 'أهلاً بك! نسعد بخدمتك.';
            $orderLinksMsg = $this->formatOrderingLinksMessage($instagramItem->website_url, $instagramItem->android_link, $instagramItem->ios_link);
            if ($orderLinksMsg) {
                $fallback .= "\n{$orderLinksMsg}";
            } else {
                $fallback .= ' يمكنك طرح استفسارك أو طلبك مباشرة، وسنكون سعداء بمساعدتك.';
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
