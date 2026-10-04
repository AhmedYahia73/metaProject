<?php

namespace App\Http\Controllers\api\user;

use App\Events\InstagramEvent;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\WhatsItem;
use App\Services\MetaPageTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ChatController extends Controller
{
    private const GRAPH_API_BASE = 'https://graph.facebook.com/v21.0';

    // ─────────────────────────────────────────────────────────────────────────
    // Messenger Endpoints
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List all Facebook Pages for the authenticated user with conversation counts & unread counters.
     * Supports search (by page_name or page_id) and pagination.
     */
    public function messengerPages(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = $user->messengerAccounts()->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('page_name', 'like', "%{$search}%")
                    ->orWhere('page_id', 'like', "%{$search}%");
            });
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $transform = function (MessengerAccount $account) use ($user) {
            $unreadCount = Chat::where('user_id', $user->id)
                ->where('messenger_account_id', $account->id)
                ->unread()
                ->count();

            $totalConversations = Chat::where('user_id', $user->id)
                ->where('messenger_account_id', $account->id)
                ->whereNotNull('messenger_sender_id')
                ->distinct('messenger_sender_id')
                ->count('messenger_sender_id');

            $subInfo = $account->getSubscriptionInfo();

            return [
                'id' => $account->id,
                'page_id' => $account->page_id,
                'page_name' => $account->page_name,
                'status' => $account->status,
                'msg_number' => $account->msg_number,
                'unread_count' => $unreadCount,
                'total_conversations' => $totalConversations,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
                'created_at' => $account->created_at,
                'profile_picture' => "https://graph.facebook.com/{$account->page_id}/picture?type=large",
            ];
        };

        if ($isPaginated) {
            $pages = $query->paginate($perPage);
            $pages->through($transform);

            return response()->json([
                'status' => true,
                'data' => $pages->items(),
                'pagination' => $this->extractPaginationMeta($pages),
            ]);
        }

        $pages = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'data' => $pages,
        ]);
    }

    /**
     * List customer conversations for a specific Facebook Page.
     * Supports search (by customer name, sender_id/PSID, or last message) and pagination.
     */
    public function messengerConversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'page_id' => 'required|string',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $account = $user->messengerAccounts()
            ->where('page_id', $request->page_id)
            ->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Messenger page.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Get distinct sender IDs for this page
        $allChats = Chat::where('user_id', $user->id)
            ->where('messenger_account_id', $account->id)
            ->whereNotNull('messenger_sender_id')
            ->orderBy('id', 'desc')
            ->get();

        // Group by sender_id (customer PSID)
        $grouped = $allChats->groupBy('messenger_sender_id');

        $conversations = $grouped->map(function (Collection $chats, string $senderId) {
            $latest = $chats->first();
            $customerName = $chats->firstWhere('name', '!==', null)?->name ?? 'Messenger User';
            $unreadCount = $chats->where('is_admin', false)->where('is_read', false)->count();

            return [
                'sender_id' => $senderId,
                'name' => $customerName,
                'last_message' => $latest->message,
                'last_message_at' => $latest->created_at?->toDateTimeString(),
                'last_sender_type' => $latest->sender_type ?: ($latest->is_admin ? 'bot' : 'customer'),
                'unread_count' => $unreadCount,
            ];
        })->values();

        // Apply search filter
        if ($request->filled('search')) {
            $search = mb_strtolower(trim($request->search));
            $conversations = $conversations->filter(function ($item) use ($search) {
                return str_contains(mb_strtolower((string) $item['name']), $search)
                    || str_contains((string) $item['sender_id'], $search)
                    || str_contains(mb_strtolower((string) $item['last_message']), $search);
            })->values();
        }

        // Sort by latest message date descending
        $conversations = $conversations->sortByDesc('last_message_at')->values();

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);
        $page = $request->integer('page', 1);

        if ($isPaginated) {
            $result = $this->paginateCollection($conversations, $perPage, $page, $request);

            return response()->json([
                'status' => true,
                'page_id' => $account->page_id,
                'page_name' => $account->page_name,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
            ]);
        }

        return response()->json([
            'status' => true,
            'page_id' => $account->page_id,
            'page_name' => $account->page_name,
            'data' => $conversations,
        ]);
    }

    /**
     * View message history between the restaurant and a Messenger customer.
     * Automatically marks unread customer messages as read.
     * Supports search (by message text) and pagination.
     */
    public function messengerMessages(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'page_id' => 'required|string',
            'sender_id' => 'required|string',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $account = $user->messengerAccounts()
            ->where('page_id', $request->page_id)
            ->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Messenger page.',
            ], Response::HTTP_FORBIDDEN);
        }

        $senderId = $request->sender_id;

        // Auto mark unread customer messages as read
        Chat::where('user_id', $user->id)
            ->where('messenger_account_id', $account->id)
            ->where('messenger_sender_id', $senderId)
            ->where('is_admin', false)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $query = Chat::where('user_id', $user->id)
            ->where('messenger_account_id', $account->id)
            ->where('messenger_sender_id', $senderId)
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('message', 'like', "%{$search}%");
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 30);

        $transform = function (Chat $chat) {
            return [
                'id' => $chat->id,
                'message' => $chat->message,
                'is_image' => (bool) $chat->is_image,
                'is_admin' => (bool) $chat->is_admin,
                'sender_type' => $chat->sender_type ?: ($chat->is_admin ? 'bot' : 'customer'),
                'is_read' => (bool) $chat->is_read,
                'read_at' => $chat->read_at?->toDateTimeString(),
                'created_at' => $chat->created_at?->toDateTimeString(),
            ];
        };

        if ($isPaginated) {
            $messages = $query->paginate($perPage);
            $messages->through($transform);

            return response()->json([
                'status' => true,
                'page_id' => $account->page_id,
                'page_name' => $account->page_name,
                'sender_id' => $senderId,
                'data' => $messages->items(),
                'pagination' => $this->extractPaginationMeta($messages),
            ]);
        }

        $messages = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'page_id' => $account->page_id,
            'page_name' => $account->page_name,
            'sender_id' => $senderId,
            'data' => $messages,
        ]);
    }

    /**
     * Send a manual reply to a Messenger customer.
     * Dispatches message via Meta Send API, stores in chats table as agent message.
     */
    public function sendMessengerMessage(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'page_id' => 'required|string',
            'recipient_id' => 'required|string',
            'message' => 'required|string|max:2000',
        ]);

        $account = $user->messengerAccounts()
            ->where('page_id', $validated['page_id'])
            ->where('status', 'active')
            ->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Messenger page.',
            ], Response::HTTP_FORBIDDEN);
        }

        $recipientId = $validated['recipient_id'];
        $messageText = trim($validated['message']);

        // Send via Meta Messenger Send API
        $response = Http::withToken($account->page_access_token)
            ->post(self::GRAPH_API_BASE.'/me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $messageText],
                'messaging_type' => 'RESPONSE',
            ]);

        // If sending failed due to expired/invalid token, attempt auto-refresh and retry once
        if (! $response->successful()) {
            /** @var MetaPageTokenService $tokenService */
            $tokenService = app(MetaPageTokenService::class);
            if ($tokenService->isTokenExpiredError($response->status(), $response->json() ?? [])) {
                $refreshedToken = $tokenService->refreshMessengerAccountToken($account);
                if ($refreshedToken) {
                    $response = Http::withToken($refreshedToken)
                        ->post(self::GRAPH_API_BASE.'/me/messages', [
                            'recipient' => ['id' => $recipientId],
                            'message' => ['text' => $messageText],
                            'messaging_type' => 'RESPONSE',
                        ]);
                }
            }
        }

        $metaMessageId = data_get($response->json(), 'message_id');
        $isSentSuccessfully = $response->successful() && ! empty($metaMessageId);

        if (! $isSentSuccessfully) {
            Log::error('SendMessengerMessage failed', [
                'user_id' => $user->id,
                'page_id' => $account->page_id,
                'recipient_id' => $recipientId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send message via Messenger API.',
                'error' => data_get($response->json(), 'error.message', 'Unknown error occurred while sending message.'),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Only save to DB and decrement quota after Meta confirms successful send
        if ((int) $account->msg_number > 0) {
            $account->decrement('msg_number');
        }

        $chat = Chat::create([
            'user_id' => $user->id,
            'messenger_account_id' => $account->id,
            'name' => 'Messenger User',
            'phone' => null,
            'message' => $messageText,
            'is_image' => false,
            'is_admin' => true,
            'sender_type' => 'agent',
            'is_read' => true,
            'read_at' => now(),
            'channel' => 'messenger',
            'messenger_sender_id' => $recipientId,
            'meta_message_id' => $metaMessageId,
        ]);

        MsgSend::create([
            'user_id' => $user->id,
            'messenger_account_id' => $account->id,
            'channel' => 'messenger',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'id' => $chat->id,
                'message' => $chat->message,
                'sender_type' => 'agent',
                'is_read' => true,
                'created_at' => $chat->created_at?->toDateTimeString(),
            ],
        ], Response::HTTP_CREATED);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WhatsApp Endpoints
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List all WhatsApp numbers for the authenticated user with conversation counts & unread counters.
     * Supports search (by phone or phone_number_id) and pagination.
     */
    public function whatsNumbers(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = $user->whatsItems()->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('phone_number_id', 'like', "%{$search}%");
            });
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $transform = function (WhatsItem $item) use ($user) {
            $unreadCount = Chat::where('user_id', $user->id)
                ->where('whats_item_id', $item->id)
                ->unread()
                ->count();

            $totalConversations = Chat::where('user_id', $user->id)
                ->where('whats_item_id', $item->id)
                ->whereNotNull('phone')
                ->distinct('phone')
                ->count('phone');

            $subInfo = $item->getSubscriptionInfo();

            return [
                'id' => $item->id,
                'phone' => $item->phone,
                'phone_number_id' => $item->phone_number_id,
                'phone_status' => $item->phone_status,
                'msg_number' => $item->msg_number,
                'unread_count' => $unreadCount,
                'total_conversations' => $totalConversations,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
                'created_at' => $item->created_at,
                'profile_picture' => $item->getProfilePictureUrl(),
            ];
        };

        if ($isPaginated) {
            $items = $query->paginate($perPage);
            $items->through($transform);

            return response()->json([
                'status' => true,
                'data' => $items->items(),
                'pagination' => $this->extractPaginationMeta($items),
            ]);
        }

        $items = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'data' => $items,
        ]);
    }

    /**
     * List customer conversations for a specific WhatsApp number.
     * Supports search (by customer name, phone number, or last message) and pagination.
     */
    public function whatsConversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'whats_item_id' => 'required|exists:whats_items,id',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $item = $user->whatsItems()->findOrFail($request->whats_item_id);

        if (! $item->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this WhatsApp number.',
            ], Response::HTTP_FORBIDDEN);
        }

        $allChats = Chat::where('user_id', $user->id)
            ->where('whats_item_id', $item->id)
            ->whereNotNull('phone')
            ->orderBy('id', 'desc')
            ->get();

        $grouped = $allChats->groupBy('phone');

        $conversations = $grouped->map(function (Collection $chats, string $customerPhone) {
            $latest = $chats->first();
            $customerName = $chats->firstWhere('name', '!==', null)?->name ?? $customerPhone;
            $unreadCount = $chats->where('is_admin', false)->where('is_read', false)->count();

            return [
                'phone' => $customerPhone,
                'name' => $customerName,
                'last_message' => $latest->message,
                'last_message_at' => $latest->created_at?->toDateTimeString(),
                'last_sender_type' => $latest->sender_type ?: ($latest->is_admin ? 'bot' : 'customer'),
                'unread_count' => $unreadCount,
            ];
        })->values();

        // Apply search filter
        if ($request->filled('search')) {
            $search = mb_strtolower(trim($request->search));
            $conversations = $conversations->filter(function ($conv) use ($search) {
                return str_contains(mb_strtolower((string) $conv['name']), $search)
                    || str_contains((string) $conv['phone'], $search)
                    || str_contains(mb_strtolower((string) $conv['last_message']), $search);
            })->values();
        }

        // Sort by latest message date descending
        $conversations = $conversations->sortByDesc('last_message_at')->values();

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);
        $page = $request->integer('page', 1);

        if ($isPaginated) {
            $result = $this->paginateCollection($conversations, $perPage, $page, $request);

            return response()->json([
                'status' => true,
                'whats_item_id' => $item->id,
                'phone' => $item->phone,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
            ]);
        }

        return response()->json([
            'status' => true,
            'whats_item_id' => $item->id,
            'phone' => $item->phone,
            'data' => $conversations,
        ]);
    }

    /**
     * View message history between the restaurant and a WhatsApp customer.
     * Automatically marks unread customer messages as read.
     * Supports search (by message text) and pagination.
     */
    public function whatsMessages(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'whats_item_id' => 'required|exists:whats_items,id',
            'phone' => 'required|string',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $item = $user->whatsItems()->findOrFail($request->whats_item_id);

        if (! $item->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this WhatsApp number.',
            ], Response::HTTP_FORBIDDEN);
        }
        $customerPhone = $request->phone;

        // Auto mark unread customer messages as read
        Chat::where('user_id', $user->id)
            ->where('whats_item_id', $item->id)
            ->where('phone', $customerPhone)
            ->where('is_admin', false)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $query = Chat::where('user_id', $user->id)
            ->where('whats_item_id', $item->id)
            ->where('phone', $customerPhone)
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('message', 'like', "%{$search}%");
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 30);

        $transform = function (Chat $chat) {
            return [
                'id' => $chat->id,
                'message' => $chat->message,
                'is_image' => (bool) $chat->is_image,
                'is_admin' => (bool) $chat->is_admin,
                'sender_type' => $chat->sender_type ?: ($chat->is_admin ? 'bot' : 'customer'),
                'is_read' => (bool) $chat->is_read,
                'read_at' => $chat->read_at?->toDateTimeString(),
                'created_at' => $chat->created_at?->toDateTimeString(),
            ];
        };

        if ($isPaginated) {
            $messages = $query->paginate($perPage);
            $messages->through($transform);

            return response()->json([
                'status' => true,
                'whats_item_id' => $item->id,
                'restaurant_phone' => $item->phone,
                'customer_phone' => $customerPhone,
                'data' => $messages->items(),
                'pagination' => $this->extractPaginationMeta($messages),
            ]);
        }

        $messages = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'whats_item_id' => $item->id,
            'restaurant_phone' => $item->phone,
            'customer_phone' => $customerPhone,
            'data' => $messages,
        ]);
    }

    /**
     * Send a manual reply to a WhatsApp customer.
     * Dispatches message via Meta Cloud API, stores in chats table as agent message.
     */
    public function sendWhatsMessage(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'whats_item_id' => 'required|exists:whats_items,id',
            'phone' => 'required|string',
            'message' => 'required|string|max:4096',
        ]);

        $item = $user->whatsItems()
            ->where('phone_status', 'active')
            ->findOrFail($validated['whats_item_id']);

        if (! $item->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this WhatsApp number.',
            ], Response::HTTP_FORBIDDEN);
        }

        $to = preg_replace('/[^0-9]/', '', $validated['phone']);
        if (strlen($to) === 11 && str_starts_with($to, '01')) {
            $to = '2'.$to;
        }

        $token = $item->access_token ?: config('services.meta.system_user_token');
        $messageText = trim($validated['message']);

        // Send via WhatsApp Cloud API
        $response = Http::withToken($token)
            ->post(self::GRAPH_API_BASE."/{$item->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $messageText],
            ]);

        $metaMessageId = data_get($response->json(), 'messages.0.id');
        $isSentSuccessfully = $response->successful() && ! empty($metaMessageId);

        if (! $isSentSuccessfully) {
            Log::error('SendWhatsMessage failed', [
                'user_id' => $user->id,
                'whats_item_id' => $item->id,
                'to' => $to,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send WhatsApp message via Meta Cloud API.',
                'error' => data_get($response->json(), 'error.message', 'Unknown error occurred while sending WhatsApp message.'),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Only save to DB and decrement quota after Meta confirms successful send
        if ((int) $item->msg_number > 0) {
            $item->decrement('msg_number');
        }

        $chat = Chat::create([
            'user_id' => $user->id,
            'whats_item_id' => $item->id,
            'name' => null,
            'phone' => $to,
            'message' => $messageText,
            'is_image' => false,
            'is_admin' => true,
            'sender_type' => 'agent',
            'is_read' => true,
            'read_at' => now(),
            'channel' => 'whatsapp',
            'meta_message_id' => $metaMessageId,
        ]);

        MsgSend::create([
            'user_id' => $user->id,
            'whats_item_id' => $item->id,
            'channel' => 'whatsapp',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp message sent successfully.',
            'data' => [
                'id' => $chat->id,
                'message' => $chat->message,
                'sender_type' => 'agent',
                'is_read' => true,
                'created_at' => $chat->created_at?->toDateTimeString(),
            ],
        ], Response::HTTP_CREATED);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Instagram Endpoints
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List all Instagram accounts for the authenticated user with conversation counts & unread counters.
     */
    public function instagramAccounts(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = $user->instagramItems()->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('instagram_id', 'like', "%{$search}%");
            });
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $transform = function (InstagramItem $item) use ($user) {
            $unreadCount = Chat::where('user_id', $user->id)
                ->where('instagram_item_id', $item->id)
                ->unread()
                ->count();

            $totalConversations = Chat::where('user_id', $user->id)
                ->where('instagram_item_id', $item->id)
                ->whereNotNull('instagram_sender_id')
                ->distinct('instagram_sender_id')
                ->count('instagram_sender_id');

            $subInfo = $item->getSubscriptionInfo();

            return [
                'id' => $item->id,
                'instagram_id' => $item->instagram_id,
                'username' => $item->username,
                'name' => $item->name,
                'profile_picture_url' => $item->profile_picture_url,
                'status' => $item->status,
                'msg_number' => $item->msg_number,
                'unread_count' => $unreadCount,
                'total_conversations' => $totalConversations,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
                'created_at' => $item->created_at,
            ];
        };

        if ($isPaginated) {
            $items = $query->paginate($perPage);
            $items->through($transform);

            return response()->json([
                'status' => true,
                'data' => $items->items(),
                'pagination' => $this->extractPaginationMeta($items),
            ]);
        }

        $items = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'data' => $items,
        ]);
    }

    /**
     * List customer conversations for a specific Instagram account.
     */
    public function instagramConversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'instagram_item_id' => 'required|integer',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $item = $user->instagramItems()
            ->findOrFail($request->instagram_item_id);

        if (! $item->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Instagram account.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Clean up known ghost records where page or bot echo recipient was stored as a sender
        $ghostSenderIds = array_values(array_filter([
            (string) $item->instagram_id,
            (string) $item->page_id,
            '1121098273691944',
            '1108645398315379',
        ]));

        if (! empty($ghostSenderIds)) {
            Chat::where('user_id', $user->id)
                ->where('instagram_item_id', $item->id)
                ->whereIn('instagram_sender_id', $ghostSenderIds)
                ->delete();
        }

        $allChats = Chat::where('user_id', $user->id)
            ->where('instagram_item_id', $item->id)
            ->whereNotNull('instagram_sender_id')
            ->whereNotIn('instagram_sender_id', $ghostSenderIds)
            ->orderBy('id', 'desc')
            ->get();

        $grouped = $allChats->groupBy('instagram_sender_id');

        $conversations = $grouped->map(function (Collection $chats, string $senderId) use ($item) {
            $latest = $chats->first();

            // Find customer name if exists and is not generic
            $customerChat = $chats->first(fn ($c) => ! empty($c->name) && $c->name !== 'Instagram User');
            $customerName = $customerChat?->name;

            // If still generic or missing, attempt to resolve from Instagram Graph API
            if (empty($customerName) && ! empty($item->access_token)) {
                $profile = $this->getInstagramUserProfile($item->access_token, $senderId);
                $resolvedName = ! empty($profile['name']) && $profile['name'] !== 'Instagram User'
                    ? $profile['name']
                    : ($profile['username'] ?? null);

                if (! empty($resolvedName)) {
                    $customerName = $resolvedName;
                    Chat::where('instagram_item_id', $item->id)
                        ->where('instagram_sender_id', $senderId)
                        ->where(function ($q) {
                            $q->whereNull('name')->orWhere('name', 'Instagram User');
                        })
                        ->update(['name' => $customerName]);
                }
            }

            $unreadCount = $chats->where('is_admin', false)->where('is_read', false)->count();

            return [
                'sender_id' => $senderId,
                'name' => $customerName ?: 'Instagram User',
                'last_message' => $latest->message,
                'last_message_at' => $latest->created_at?->toDateTimeString(),
                'last_sender_type' => $latest->sender_type ?: ($latest->is_admin ? 'bot' : 'customer'),
                'unread_count' => $unreadCount,
            ];
        })->values();

        if ($request->filled('search')) {
            $search = mb_strtolower(trim($request->search));
            $conversations = $conversations->filter(function ($conv) use ($search) {
                return str_contains(mb_strtolower((string) $conv['name']), $search)
                    || str_contains((string) $conv['sender_id'], $search)
                    || str_contains(mb_strtolower((string) $conv['last_message']), $search);
            })->values();
        }

        $conversations = $conversations->sortByDesc('last_message_at')->values();

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);
        $page = $request->integer('page', 1);

        if ($isPaginated) {
            $result = $this->paginateCollection($conversations, $perPage, $page, $request);

            return response()->json([
                'status' => true,
                'instagram_item_id' => $item->id,
                'username' => $item->username,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
            ]);
        }

        return response()->json([
            'status' => true,
            'instagram_item_id' => $item->id,
            'username' => $item->username,
            'data' => $conversations,
        ]);
    }

    /**
     * View message history between the restaurant and an Instagram user.
     */
    public function instagramMessages(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'instagram_item_id' => 'required|integer',
            'sender_id' => 'required|string',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $item = $user->instagramItems()
            ->findOrFail($request->instagram_item_id);

        if (! $item->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Instagram account.',
            ], Response::HTTP_FORBIDDEN);
        }

        $senderId = $request->sender_id;

        // Auto mark unread as read
        Chat::where('user_id', $user->id)
            ->where('instagram_item_id', $item->id)
            ->where('instagram_sender_id', $senderId)
            ->where('is_admin', false)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $query = Chat::where('user_id', $user->id)
            ->where('instagram_item_id', $item->id)
            ->where('instagram_sender_id', $senderId)
            ->orderBy('id', 'asc');

        if ($request->filled('search')) {
            $query->where('message', 'like', "%{$request->search}%");
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 50);

        $transform = function (Chat $chat) {
            return [
                'id' => $chat->id,
                'message' => $chat->message,
                'is_image' => (bool) $chat->is_image,
                'is_admin' => (bool) $chat->is_admin,
                'sender_type' => $chat->sender_type ?: ($chat->is_admin ? 'bot' : 'customer'),
                'is_read' => (bool) $chat->is_read,
                'read_at' => $chat->read_at?->toDateTimeString(),
                'created_at' => $chat->created_at?->toDateTimeString(),
            ];
        };

        if ($isPaginated) {
            $messages = $query->paginate($perPage);
            $messages->through($transform);

            return response()->json([
                'status' => true,
                'instagram_item_id' => $item->id,
                'username' => $item->username,
                'sender_id' => $senderId,
                'data' => $messages->items(),
                'pagination' => $this->extractPaginationMeta($messages),
            ]);
        }

        $messages = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'instagram_item_id' => $item->id,
            'username' => $item->username,
            'sender_id' => $senderId,
            'data' => $messages,
        ]);
    }

    /**
     * Send a manual reply to an Instagram customer.
     */
    public function sendInstagramMessage(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'instagram_item_id' => 'required|integer',
            'recipient_id' => 'required|string',
            'message' => 'required|string|max:2000',
        ]);

        $item = $user->instagramItems()
            ->where('status', 'active')
            ->findOrFail($validated['instagram_item_id']);

        if (! $item->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Instagram account.',
            ], Response::HTTP_FORBIDDEN);
        }

        $recipientId = $validated['recipient_id'];
        $messageText = trim($validated['message']);

        // Send via Meta Send API
        $response = Http::withToken($item->access_token)
            ->post(self::GRAPH_API_BASE.'/me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $messageText],
            ]);

        // If sending failed due to expired/invalid token, attempt auto-refresh and retry once
        if (! $response->successful()) {
            /** @var MetaPageTokenService $tokenService */
            $tokenService = app(MetaPageTokenService::class);
            if ($tokenService->isTokenExpiredError($response->status(), $response->json() ?? [])) {
                $refreshedToken = $tokenService->refreshInstagramItemToken($item);
                if ($refreshedToken) {
                    $response = Http::withToken($refreshedToken)
                        ->post(self::GRAPH_API_BASE.'/me/messages', [
                            'recipient' => ['id' => $recipientId],
                            'message' => ['text' => $messageText],
                        ]);
                }
            }
        }

        $metaMessageId = data_get($response->json(), 'message_id');
        $isSentSuccessfully = $response->successful() && ! empty($metaMessageId);

        if (! $isSentSuccessfully) {
            Log::error('Instagram manual send failed', [
                'instagram_item_id' => $item->id,
                'recipient_id' => $recipientId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send message via Instagram API.',
                'meta_error' => data_get($response->json(), 'error.message', 'Unknown Meta API error'),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Only save to DB and decrement quota after Meta confirms successful send
        if ((int) $item->msg_number > 0) {
            $item->decrement('msg_number');
        }

        $existingName = Chat::where('instagram_item_id', $item->id)
            ->where('instagram_sender_id', $recipientId)
            ->whereNotNull('name')
            ->where('name', '!=', 'Instagram User')
            ->value('name');

        $chat = Chat::create([
            'user_id' => $user->id,
            'instagram_item_id' => $item->id,
            'name' => $existingName ?: 'Instagram User',
            'phone' => null,
            'message' => $messageText,
            'is_image' => false,
            'is_admin' => true,
            'sender_type' => 'agent',
            'is_read' => true,
            'read_at' => now(),
            'channel' => 'instagram',
            'instagram_sender_id' => $recipientId,
            'meta_message_id' => $metaMessageId,
        ]);

        MsgSend::create([
            'user_id' => $user->id,
            'instagram_item_id' => $item->id,
            'channel' => 'instagram',
        ]);

        // Realtime broadcast to admin dashboard
        try {
            $chatData = $chat->toArray();
            $chatData['instagram_id'] = $item->instagram_id;
            InstagramEvent::dispatch($chatData);
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch InstagramEvent on manual send: '.$e->getMessage());
        }

        return response()->json([
            'status' => true,
            'message' => 'Instagram message sent successfully.',
            'data' => [
                'id' => $chat->id,
                'message' => $chat->message,
                'sender_type' => 'agent',
                'is_read' => true,
                'created_at' => $chat->created_at?->toDateTimeString(),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Manually mark conversation or specific messages as read.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'channel' => 'required|in:messenger,whatsapp,instagram',
            'page_id' => 'required_if:channel,messenger|string',
            'sender_id' => 'required_if:channel,messenger,instagram|string',
            'whats_item_id' => 'required_if:channel,whatsapp|integer',
            'instagram_item_id' => 'required_if:channel,instagram|integer',
            'phone' => 'required_if:channel,whatsapp|string',
        ]);

        $query = Chat::where('user_id', $user->id)
            ->where('channel', $validated['channel'])
            ->where('is_admin', false)
            ->where('is_read', false);

        if ($validated['channel'] === 'messenger') {
            $account = $user->messengerAccounts()->where('page_id', $validated['page_id'])->firstOrFail();

            if (! $account->hasActiveSubscription()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Subscription required or message quota exceeded for this Messenger page.',
                ], Response::HTTP_FORBIDDEN);
            }

            $query->where('messenger_account_id', $account->id)
                ->where('messenger_sender_id', $validated['sender_id']);
        } elseif ($validated['channel'] === 'instagram') {
            $item = $user->instagramItems()->findOrFail($validated['instagram_item_id']);

            if (! $item->hasActiveSubscription()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Subscription required or message quota exceeded for this Instagram account.',
                ], Response::HTTP_FORBIDDEN);
            }

            $query->where('instagram_item_id', $item->id)
                ->where('instagram_sender_id', $validated['sender_id']);
        } else {
            $item = $user->whatsItems()->findOrFail($validated['whats_item_id']);

            if (! $item->hasActiveSubscription()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Subscription required or message quota exceeded for this WhatsApp number.',
                ], Response::HTTP_FORBIDDEN);
            }

            $query->where('whats_item_id', $item->id)
                ->where('phone', $validated['phone']);
        }

        $count = $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => "{$count} messages marked as read.",
            'marked_count' => $count,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function extractPaginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }

    private function paginateCollection(Collection $collection, int $perPage, int $currentPage, Request $request): array
    {
        $total = $collection->count();
        $slice = $collection->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return [
            'data' => $paginator->items(),
            'pagination' => $this->extractPaginationMeta($paginator),
        ];
    }

    /**
     * Fetch user profile from Instagram Graph API (name, username, profile_pic).
     *
     * @return array{name?: string, username?: string, profile_pic?: string}
     */
    private function getInstagramUserProfile(string $accessToken, string $senderId): array
    {
        if (empty($accessToken) || empty($senderId)) {
            return [];
        }

        try {
            $graphVersion = config('services.meta.graph_version', 'v21.0');
            $response = Http::timeout(5)->get("https://graph.facebook.com/{$graphVersion}/{$senderId}", [
                'fields' => 'name,username,profile_pic',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : [];
            }
        } catch (\Throwable $e) {
            // Ignore error
        }

        return [];
    }
}
