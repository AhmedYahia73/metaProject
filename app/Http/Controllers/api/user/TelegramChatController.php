<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\TelegramAccount;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class TelegramChatController extends Controller
{
    public function __construct(
        protected TelegramService $telegramService
    ) {}

    /**
     * List all connected Telegram bots/accounts for the user with conversation counts & unread counters.
     * Matches Messenger `chat/messenger/pages`.
     */
    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = $user->telegramAccounts()->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('bot_name', 'like', "%{$search}%")
                    ->orWhere('bot_username', 'like', "%{$search}%")
                    ->orWhere('bot_id', 'like', "%{$search}%");
            });
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $transform = function (TelegramAccount $account) use ($user) {
            $unreadCount = Chat::where('user_id', $user->id)
                ->where('telegram_account_id', $account->id)
                ->where('is_admin', false)
                ->where('is_read', false)
                ->count();

            $totalConversations = Chat::where('user_id', $user->id)
                ->where('telegram_account_id', $account->id)
                ->whereNotNull('telegram_sender_id')
                ->distinct('telegram_sender_id')
                ->count('telegram_sender_id');

            $subInfo = $account->getSubscriptionInfo();

            return [
                'id' => $account->id,
                'bot_id' => $account->bot_id,
                'bot_username' => $account->bot_username,
                'bot_name' => $account->bot_name,
                'status' => $account->status,
                'msg_number' => $account->msg_number,
                'unread_count' => $unreadCount,
                'total_conversations' => $totalConversations,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
                'created_at' => $account->created_at?->toDateTimeString(),
            ];
        };

        if ($isPaginated) {
            $accounts = $query->paginate($perPage);
            $accounts->through($transform);

            return response()->json([
                'status' => true,
                'data' => $accounts->items(),
                'pagination' => $this->extractPaginationMeta($accounts),
            ]);
        }

        $accounts = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * List customer conversations for a specific Telegram bot account.
     * Matches Messenger `chat/messenger/conversations`.
     */
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'telegram_account_id' => 'sometimes|integer|exists:telegram_accounts,id',
            'bot_id' => 'sometimes|string',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = $user->telegramAccounts();
        if ($request->filled('telegram_account_id')) {
            $query->where('id', $request->telegram_account_id);
        } elseif ($request->filled('bot_id')) {
            $query->where('bot_id', $request->bot_id);
        }

        /** @var TelegramAccount $account */
        $account = $query->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Telegram bot.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Get distinct customer sender IDs for this account
        $allChats = Chat::where('user_id', $user->id)
            ->where('telegram_account_id', $account->id)
            ->whereNotNull('telegram_sender_id')
            ->orderBy('id', 'desc')
            ->get();

        $grouped = $allChats->groupBy('telegram_sender_id');

        $conversations = $grouped->map(function (Collection $chats, string $senderId) {
            $latest = $chats->first();

            $customerChat = $chats->first(fn ($c) => ! empty($c->name) && $c->name !== 'Telegram User');
            $customerName = $customerChat?->name ?: 'Telegram User';

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

        if ($request->filled('search')) {
            $search = mb_strtolower(trim($request->search));
            $conversations = $conversations->filter(function ($item) use ($search) {
                return str_contains(mb_strtolower((string) $item['name']), $search)
                    || str_contains((string) $item['sender_id'], $search)
                    || str_contains(mb_strtolower((string) $item['last_message']), $search);
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
                'telegram_account_id' => $account->id,
                'bot_id' => $account->bot_id,
                'bot_username' => $account->bot_username,
                'bot_name' => $account->bot_name,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
            ]);
        }

        return response()->json([
            'status' => true,
            'telegram_account_id' => $account->id,
            'bot_id' => $account->bot_id,
            'bot_username' => $account->bot_username,
            'bot_name' => $account->bot_name,
            'data' => $conversations,
        ]);
    }

    /**
     * View message history between the bot and a Telegram customer.
     * Automatically marks unread customer messages as read.
     * Matches Messenger `chat/messenger/messages`.
     */
    public function messages(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'telegram_account_id' => 'sometimes|integer|exists:telegram_accounts,id',
            'bot_id' => 'sometimes|string',
            'sender_id' => 'required|string',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = $user->telegramAccounts();
        if ($request->filled('telegram_account_id')) {
            $query->where('id', $request->telegram_account_id);
        } elseif ($request->filled('bot_id')) {
            $query->where('bot_id', $request->bot_id);
        }

        /** @var TelegramAccount $account */
        $account = $query->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Telegram bot.',
            ], Response::HTTP_FORBIDDEN);
        }

        $senderId = (string) $request->sender_id;

        // Auto mark unread customer messages as read
        Chat::where('user_id', $user->id)
            ->where('telegram_account_id', $account->id)
            ->where('telegram_sender_id', $senderId)
            ->where('is_admin', false)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $query = Chat::where('user_id', $user->id)
            ->where('telegram_account_id', $account->id)
            ->where('telegram_sender_id', $senderId)
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
                'telegram_account_id' => $account->id,
                'bot_id' => $account->bot_id,
                'bot_username' => $account->bot_username,
                'sender_id' => $senderId,
                'data' => $messages->items(),
                'pagination' => $this->extractPaginationMeta($messages),
            ]);
        }

        $messages = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'telegram_account_id' => $account->id,
            'bot_id' => $account->bot_id,
            'bot_username' => $account->bot_username,
            'sender_id' => $senderId,
            'data' => $messages,
        ]);
    }

    /**
     * Send a manual reply to a Telegram customer.
     * Matches Messenger `chat/messenger/send`.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'telegram_account_id' => 'sometimes|integer|exists:telegram_accounts,id',
            'bot_id' => 'sometimes|string',
            'recipient_id' => 'sometimes|string',
            'sender_id' => 'sometimes|string',
            'message' => 'required|string|max:4000',
        ]);

        $query = $user->telegramAccounts()->where('status', 'active');
        if (! empty($validated['telegram_account_id'])) {
            $query->where('id', $validated['telegram_account_id']);
        } elseif (! empty($validated['bot_id'])) {
            $query->where('bot_id', $validated['bot_id']);
        }

        /** @var TelegramAccount $account */
        $account = $query->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this Telegram bot.',
            ], Response::HTTP_FORBIDDEN);
        }

        $recipientId = (string) ($validated['recipient_id'] ?? $validated['sender_id']);
        if (empty($recipientId)) {
            return response()->json([
                'status' => false,
                'message' => 'The recipient_id or sender_id is required.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $messageText = trim($validated['message']);

        // Send via Telegram API
        $sendResult = $this->telegramService->sendMessage($account->bot_token, $recipientId, $messageText);

        if (! $sendResult['success']) {
            return response()->json([
                'status' => false,
                'message' => $sendResult['message'] ?? 'Failed to send message via Telegram API.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Store agent reply in chats table
        $chat = Chat::create([
            'user_id' => $user->id,
            'telegram_account_id' => $account->id,
            'telegram_sender_id' => $recipientId,
            'channel' => 'telegram',
            'name' => $account->bot_name ?: 'Bot Agent',
            'message' => $messageText,
            'is_image' => false,
            'is_admin' => true,
            'is_read' => true,
            'read_at' => now(),
            'sender_type' => 'agent',
            'meta_message_id' => (string) ($sendResult['message_id'] ?? ''),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'id' => $chat->id,
                'telegram_account_id' => $account->id,
                'sender_id' => $recipientId,
                'message' => $chat->message,
                'sender_type' => $chat->sender_type,
                'created_at' => $chat->created_at?->toDateTimeString(),
            ],
        ]);
    }

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
