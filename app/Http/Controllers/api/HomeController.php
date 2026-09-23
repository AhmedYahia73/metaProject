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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

            // 2. Resolve the restaurant user via phone_number_id
            $phoneNumberId = data_get($data, 'entry.0.changes.0.value.metadata.phone_number_id');

            // Handle Meta test button from Developer Dashboard (sends dummy ID 123456123)
            if ((string) $phoneNumberId === '123456123') {
                $phoneNumberId = config('services.meta.phone_number_id', '1296872370175605');
            }

            if (! $phoneNumberId) {
                return response()->json(['status' => 'ignored'], Response::HTTP_OK);
            }

            /** @var User|null $restaurant */
            $restaurant = User::where('phone_number_id', $phoneNumberId)
                ->where('role', 'user')
                ->first();

            if (! $restaurant) {
                Log::warning("Webhook received for unknown phone_number_id: {$phoneNumberId}");

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
                $senderPhone = $restaurant->phone ?: '201206610346';
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
                'sender' => $senderPhone,
                'message' => $messageText,
            ]);

            // 4. Check if the restaurant has an active subscription with remaining messages
            if (! $this->hasRemainingMessages($restaurant)) {
                Log::info("Webhook: message limit reached for restaurant #{$restaurant->id}");

                return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
            }

            // 5. Save the customer's incoming message
            Chat::create([
                'user_id' => $restaurant->id,
                'name' => $senderName,
                'phone' => $senderPhone,
                'message' => $messageText,
                'is_image' => false,
                'is_admin' => false,
            ]);

            // 6. Get AI reply
            $reply = $this->getAiReply($restaurant, $messageText);

            if (! $reply) {
                Log::warning("Webhook: AI returned empty reply for restaurant #{$restaurant->id}");

                return response()->json(['status' => 'ai_failed'], Response::HTTP_OK);
            }

            // 7. Send reply via WhatsApp — only record to DB if successful
            $token = $restaurant->access_token ?: config('services.meta.system_user_token');
            $sent = $this->sendTextMessage(
                accessToken: (string) $token,
                phoneNumberId: $restaurant->phone_number_id,
                to: $senderPhone,
                body: $reply,
            );

            if ($sent) {
                Chat::create([
                    'user_id' => $restaurant->id,
                    'name' => $senderName,
                    'phone' => $senderPhone,
                    'message' => $reply,
                    'is_image' => false,
                    'is_admin' => true,
                ]);

                MsgSend::create(['user_id' => $restaurant->id]);
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

        try {
            $data = $request->all();

            // Only handle page-level Messenger events
            if (data_get($data, 'object') !== 'page') {
                return response()->json(['status' => 'ignored_non_page'], Response::HTTP_OK);
            }

            $pageId = (string) data_get($data, 'entry.0.id');

            if (! $pageId) {
                return response()->json(['status' => 'no_page_id'], Response::HTTP_OK);
            }

            // Resolve restaurant via the Facebook Page ID
            /** @var MessengerAccount|null $messengerAccount */
            $messengerAccount = MessengerAccount::where('page_id', $pageId)
                ->where('status', 'active')
                ->first();

            if (! $messengerAccount) {
                Log::warning("Messenger webhook: unknown or disabled page_id: {$pageId}");

                return response()->json(['status' => 'page_not_found'], Response::HTTP_OK);
            }

            /** @var User $restaurant */
            $restaurant = $messengerAccount->user;

            // Extract the first messaging event
            $messagingEvent = data_get($data, 'entry.0.messaging.0');

            if (! $messagingEvent) {
                return response()->json(['status' => 'no_messaging_event'], Response::HTTP_OK);
            }

            // Ignore echoed messages (sent by the page itself)
            if (data_get($messagingEvent, 'message.is_echo')) {
                return response()->json(['status' => 'echo_ignored'], Response::HTTP_OK);
            }

            $senderId = (string) data_get($messagingEvent, 'sender.id');
            $messageText = trim((string) data_get($messagingEvent, 'message.text', ''));

            // Ignore non-text messages (attachments, stickers, etc.)
            if (empty($messageText)) {
                return response()->json(['status' => 'non_text_ignored'], Response::HTTP_OK);
            }

            Log::info('Messenger webhook: message received', [
                'restaurant_id' => $restaurant->id,
                'page_id' => $pageId,
                'sender_psid' => $senderId,
                'message' => $messageText,
            ]);

            // Check Messenger-specific message limit
            if (! $this->hasRemainingMessages($restaurant, 'messenger')) {
                Log::info("Messenger webhook: message limit reached for restaurant #{$restaurant->id}");

                return response()->json(['status' => 'limit_exceeded'], Response::HTTP_OK);
            }

            // Save incoming customer message
            Chat::create([
                'user_id' => $restaurant->id,
                'name' => 'Messenger User',
                'phone' => null,
                'message' => $messageText,
                'is_image' => false,
                'is_admin' => false,
                'channel' => 'messenger',
                'messenger_sender_id' => $senderId,
            ]);

            // Get AI reply (same logic as WhatsApp)
            $reply = $this->getAiReply($restaurant, $messageText);

            if (! $reply) {
                Log::warning("Messenger webhook: AI returned empty reply for restaurant #{$restaurant->id}");

                return response()->json(['status' => 'ai_failed'], Response::HTTP_OK);
            }

            // Send reply via Messenger API
            $sent = $this->sendMessengerMessage(
                pageAccessToken: $messengerAccount->page_access_token,
                recipientId: $senderId,
                text: $reply,
            );

            if ($sent) {
                Chat::create([
                    'user_id' => $restaurant->id,
                    'name' => 'Messenger User',
                    'phone' => null,
                    'message' => $reply,
                    'is_image' => false,
                    'is_admin' => true,
                    'channel' => 'messenger',
                    'messenger_sender_id' => $senderId,
                ]);

                MsgSend::create([
                    'user_id' => $restaurant->id,
                    'channel' => 'messenger',
                ]);
            } else {
                Log::warning("Messenger webhook: send failed for restaurant #{$restaurant->id} to PSID {$senderId}");
            }

            return response()->json([
                'status' => 'success',
                'reply' => $reply,
                'messenger_sent' => $sent,
            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            Log::error('Messenger webhook exception: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
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
     * Get an AI-generated reply using OpenAI Responses API with food tool-call support.
     */
    private function getAiReply(User $restaurant, string $userMessage): ?string
    {
        $restaurantid = $restaurant->restuarant_name;
        $aiContext = Setting::firstWhere('name', 'ai_context')?->value
            ?? 'أنت موظف خدمة عملاء لمطعم، ردّ بأسلوب ودي وبسيط.';

        $instructions = <<<PROMPT
        {$aiContext}

        روابط المطعم:
        Android: {$restaurant->android_link}
        iOS: {$restaurant->ios_link}

        التعليمات:
        - الرد باللغة العربية فقط.
        - لا تخترع بيانات أو أسماء أو أسعار.
        - استخدم أداة search_foods فقط للبحث عن الوجبات المتاحة.
        - لا تقترح وجبات غير متاحة أو نافدة من المخزون.
        PROMPT;

        $tools = [
            [
                'type' => 'function',
                'name' => 'search_foods',
                'description' => 'البحث في قاعدة بيانات الوجبات المتاحة.',
                'strict' => true,
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['query', 'limit'],
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'مصطلح البحث عن الوجبة.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 10,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $response = OpenAI::responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'tools' => $tools,
                'input' => $userMessage,
            ]);

            // Handle function_call tool requests from AI
            $toolOutputs = $this->resolveToolCalls($response->output ?? [], (string) $restaurant->id);

            if (! empty($toolOutputs)) {
                $response = OpenAI::responses()->create([
                    'model' => $model,
                    'instructions' => $instructions,
                    'tools' => $tools,
                    'previous_response_id' => $response->id,
                    'input' => $toolOutputs,
                ]);
            }

            return trim((string) ($response->outputText ?? '')) ?: null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI getAiReply fallback triggered: '.$e->getMessage());

            return 'أهلاً بك في مطعمنا! نسعد بخدمتك. يمكنك تصفح وجباتنا وطلبك مباشرة، أو سيتواصل معك أحد ممثلي الخدمة قريباً.';
        }
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
