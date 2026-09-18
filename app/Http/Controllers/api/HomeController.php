<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Food;
use App\Models\MsgSend;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        if ($request->isMethod('get') && $request->has('hub_challenge')) {
            return response($request->input('hub_challenge'), Response::HTTP_OK);
        }

        try {
            $data = $request->all();

            // 2. Resolve the restaurant user via phone_number_id
            $phoneNumberId = data_get($data, 'entry.0.changes.0.value.metadata.phone_number_id');

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

            $senderPhone = $incomingMessage['from'];
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
            $sent = $this->sendTextMessage(
                accessToken: $restaurant->access_token,
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

            return response()->json(['status' => 'success'], Response::HTTP_OK);

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
        $verifyToken = env('WHATSAPP_VERIFY_TOKEN');
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response((string) $challenge, Response::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Determine whether the restaurant still has messages left in its active subscription.
     */
    private function hasRemainingMessages(User $restaurant): bool
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
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->count();

        return $used < $activeOrder;
    }

    /**
     * Get an AI-generated reply using OpenAI Responses API with food tool-call support.
     */
    private function getAiReply(User $restaurant, string $userMessage): ?string
    {
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

        $response = OpenAI::responses()->create([
            'model' => 'gpt-4o',
            'instructions' => $instructions,
            'tools' => $tools,
            'input' => $userMessage,
        ]);

        // Handle function_call tool requests from AI
        $toolOutputs = $this->resolveToolCalls($response->output ?? []);

        if (! empty($toolOutputs)) {
            $response = OpenAI::responses()->create([
                'model' => 'gpt-4o',
                'instructions' => $instructions,
                'tools' => $tools,
                'previous_response_id' => $response->id,
                'input' => $toolOutputs,
            ]);
        }

        return trim((string) ($response->outputText ?? '')) ?: null;
    }

    /**
     * Resolve any tool calls the AI made and return the outputs.
     *
     * @param  array<mixed>  $outputItems
     * @return array<mixed>
     */
    private function resolveToolCalls(array $outputItems): array
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

                $foods = Food::query()
                    ->where('status', 1)
                    ->where('is_out_of_stock', 0)
                    ->where(function ($q) use ($query) {
                        $q->where('name_ar', 'like', "%{$query}%")
                            ->orWhere('description_ar', 'like', "%{$query}%");
                    })
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
        $response = Http::withToken($accessToken)
            ->post(self::GRAPH_API_BASE."/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
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
