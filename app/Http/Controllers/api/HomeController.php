<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Food;
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

            // 4. Check if the WhatsItem or restaurant has an active subscription with remaining messages
            $hasRemainingQuota = ((int) $whatsItem->msg_number) > 0;
            $hasActiveOrder = $this->hasRemainingMessages($restaurant, 'whatsapp');
            $hasLimit = $hasRemainingQuota || $hasActiveOrder;

            if (! $hasLimit) {
                Log::info("Webhook: message limit reached for WhatsItem #{$whatsItem->id} (restaurant #{$restaurant->id})");

                return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
            }

            // 5. Save the customer's incoming message
            Chat::create([
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

            // 6. Get AI reply
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
     * @param  string  $channel  'whatsapp' | 'messenger'
     */
    private function hasRemainingMessages(User $restaurant, string $channel = 'whatsapp'): bool
    {
        $today = now()->toDateString();

        $activeOrder = Order::where('user_id', $restaurant->id)
            ->where('from', '<=', $today)
            ->where('to', '>=', $today)
            ->sum('msgs');
        $from = Order::where('user_id', $restaurant->id)
            ->where('from', '<=', $today)
            ->where('to', '>=', $today)
            ->min('from');
        $to = Order::where('user_id', $restaurant->id)
            ->where('from', '<=', $today)
            ->where('to', '>=', $today)
            ->max('to');

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

            // Check Messenger-specific message limit on the MessengerAccount and active order
            $hasRemainingQuota = ((int) $messengerAccount->msg_number) > 0;
            $hasActiveOrder = $this->hasRemainingMessages($restaurant, 'messenger');
            $hasLimit = $hasRemainingQuota || $hasActiveOrder;

            Log::channel('stack')->info('[MESSENGER] ⚡ Limit check', [
                'restaurant_id' => $restaurant->id,
                'messenger_account_id' => $messengerAccount->id,
                'account_msg_number' => $messengerAccount->msg_number,
                'has_remaining_quota' => $hasRemainingQuota,
                'has_active_order' => $hasActiveOrder,
            ]);

            if (! $hasLimit) {
                Log::channel('stack')->warning("[MESSENGER] ✗ Limit exceeded for account #{$messengerAccount->id} (restaurant #{$restaurant->id})");

                return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
            }

            // Save incoming customer message
            Chat::create([
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

            Log::channel('stack')->info('[MESSENGER] ✓ Customer message saved to DB');

            // Get AI reply for Messenger using MessengerAccount context & ai_file
            Log::channel('stack')->info('[MESSENGER] ⏳ Calling OpenAI...');
            $reply = $this->getMessengerAiReply($messengerAccount, $messageText);

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
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        Log::info('Messenger webhook verify attempt', [
            'hub_mode' => $mode,
            'ip' => $request->ip(),
        ]);

        if ($mode === 'subscribe' && $token) {
            $account = MessengerAccount::where('verify_token', $token)->first();

            if ($account) {
                Log::info('Messenger webhook verified successfully.', ['page_id' => $account->page_id]);

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
        $graphVersion = config('services.meta.graph_version', 'v21.0');

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
            $linksSection = "\n\nروابط وتفاصيل التواصل:";
            if ($whatsItem->android_link) {
                $linksSection .= "\nAndroid: {$whatsItem->android_link}";
            }
            if ($whatsItem->ios_link) {
                $linksSection .= "\niOS: {$whatsItem->ios_link}";
            }
            if ($whatsItem->website_url) {
                $linksSection .= "\nالموقع الإلكتروني: {$whatsItem->website_url}";
            }
        }

        $instructions = <<<PROMPT
        {$aiContext}
        {$fileDataSection}
        {$linksSection}

        التعليمات:
        - الرد باللغة العربية فقط بأسلوب مهذب ومساعد وموجز.
        - اعتمد على البيانات المذكورة أعلاه في الرد على استفسارات العميل ولا تخترع أي معلومات أو أسعار غير موجودة.
        - إذا سأل العميل عن شيء غير مذكور في البيانات أو غير متاح، أخبره بلباقة أنه غير متوفر حالياً.
        PROMPT;

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $response = OpenAI::responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'input' => $userMessage,
            ]);

            return trim((string) ($response->outputText ?? '')) ?: null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI getAiReply fallback triggered: '.$e->getMessage());

            return 'أهلاً بك! نسعد بخدمتك. يمكنك طرح استفسارك أو طلبك مباشرة، وسنكون سعداء بمساعدتك.';
        }
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
            $linksSection = "\n\nروابط وتفاصيل التواصل:";
            if ($messengerAccount->android_link) {
                $linksSection .= "\nAndroid: {$messengerAccount->android_link}";
            }
            if ($messengerAccount->ios_link) {
                $linksSection .= "\niOS: {$messengerAccount->ios_link}";
            }
            if ($messengerAccount->website_url) {
                $linksSection .= "\nالموقع الإلكتروني: {$messengerAccount->website_url}";
            }
        }

        $instructions = <<<PROMPT
        {$aiContext}
        {$fileDataSection}
        {$linksSection}

        التعليمات:
        - الرد باللغة العربية فقط بأسلوب مهذب ومساعد وموجز.
        - اعتمد على البيانات المذكورة أعلاه في الرد على استفسارات العميل ولا تخترع أي معلومات أو أسعار غير موجودة.
        - إذا سأل العميل عن شيء غير مذكور في البيانات أو غير متاح، أخبره بلباقة أنه غير متوفر حالياً.
        PROMPT;

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $response = OpenAI::responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'input' => $userMessage,
            ]);

            return trim((string) ($response->outputText ?? '')) ?: null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI getMessengerAiReply fallback triggered: '.$e->getMessage());

            return 'أهلاً بك! نسعد بخدمتك. يمكنك طرح استفسارك أو طلبك مباشرة، وسنكون سعداء بمساعدتك.';
        }
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
