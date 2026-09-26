<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\MessengerAccount;
use App\Models\MsgSend;
use App\Models\WhatsItem;
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

            return [
                'id' => $account->id,
                'page_id' => $account->page_id,
                'page_name' => $account->page_name,
                'status' => $account->status,
                'msg_number' => $account->msg_number,
                'unread_count' => $unreadCount,
                'total_conversations' => $totalConversations,
                'created_at' => $account->created_at,
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
            ->orderBy('id', 'asc');

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

        $recipientId = $validated['recipient_id'];
        $messageText = trim($validated['message']);

        // Send via Meta Messenger Send API
        $response = Http::withToken($account->page_access_token)
            ->post(self::GRAPH_API_BASE.'/me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $messageText],
                'messaging_type' => 'RESPONSE',
            ]);

        if (! $response->successful()) {
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
                'error' => $response->json('error.message'),
            ], Response::HTTP_BAD_GATEWAY);
        }

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
            'meta_message_id' => $response->json('message_id'),
        ]);

        MsgSend::create([
            'user_id' => $user->id,
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

            return [
                'id' => $item->id,
                'phone' => $item->phone,
                'phone_number_id' => $item->phone_number_id,
                'phone_status' => $item->phone_status,
                'msg_number' => $item->msg_number,
                'unread_count' => $unreadCount,
                'total_conversations' => $totalConversations,
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
            ->orderBy('id', 'asc');

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

        if (! $response->successful()) {
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
                'error' => $response->json('error.message'),
            ], Response::HTTP_BAD_GATEWAY);
        }

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
            'meta_message_id' => data_get($response->json(), 'messages.0.id'),
        ]);

        MsgSend::create([
            'user_id' => $user->id,
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

    /**
     * Manually mark conversation or specific messages as read.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'channel' => 'required|in:messenger,whatsapp',
            'page_id' => 'required_if:channel,messenger|string',
            'sender_id' => 'required_if:channel,messenger|string',
            'whats_item_id' => 'required_if:channel,whatsapp|integer',
            'phone' => 'required_if:channel,whatsapp|string',
        ]);

        $query = Chat::where('user_id', $user->id)
            ->where('channel', $validated['channel'])
            ->where('is_admin', false)
            ->where('is_read', false);

        if ($validated['channel'] === 'messenger') {
            $account = $user->messengerAccounts()->where('page_id', $validated['page_id'])->firstOrFail();
            $query->where('messenger_account_id', $account->id)
                ->where('messenger_sender_id', $validated['sender_id']);
        } else {
            $item = $user->whatsItems()->findOrFail($validated['whats_item_id']);
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
}
