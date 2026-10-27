<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\TikTokAccount;
use App\Services\TikTokService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class TikTokChatController extends Controller
{
    public function __construct(
        protected TikTokService $tiktokService
    ) {}

    /**
     * List all connected TikTok accounts for the user with conversation counts & unread counters.
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

        $query = $user->tiktokAccounts()->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('display_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('open_id', 'like', "%{$search}%");
            });
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $transform = function (TikTokAccount $account) use ($user) {
            $unreadCount = Chat::where('user_id', $user->id)
                ->where('tiktok_account_id', $account->id)
                ->where('is_admin', false)
                ->where('is_read', false)
                ->count();

            $totalConversations = Chat::where('user_id', $user->id)
                ->where('tiktok_account_id', $account->id)
                ->whereNotNull('tiktok_sender_id')
                ->distinct('tiktok_sender_id')
                ->count('tiktok_sender_id');

            $subInfo = $account->getSubscriptionInfo();

            return [
                'id' => $account->id,
                'open_id' => $account->open_id,
                'username' => $account->username,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
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
     * List customer conversations for a specific TikTok account.
     */
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'tiktok_account_id' => 'sometimes|integer|exists:tiktok_accounts,id',
            'open_id' => 'sometimes|string',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = $user->tiktokAccounts();
        if ($request->filled('tiktok_account_id')) {
            $query->where('id', $request->tiktok_account_id);
        } elseif ($request->filled('open_id')) {
            $query->where('open_id', $request->open_id);
        }

        /** @var TikTokAccount $account */
        $account = $query->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this TikTok account.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Get distinct customer sender IDs for this account
        $allChats = Chat::where('user_id', $user->id)
            ->where('tiktok_account_id', $account->id)
            ->whereNotNull('tiktok_sender_id')
            ->orderBy('id', 'desc')
            ->get();

        $grouped = $allChats->groupBy('tiktok_sender_id');

        $conversations = $grouped->map(function (Collection $chats, string $senderId) {
            $latest = $chats->first();

            $customerChat = $chats->first(fn ($c) => ! empty($c->name) && $c->name !== 'TikTok User');
            $customerName = $customerChat?->name ?: 'TikTok User';

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
                'tiktok_account_id' => $account->id,
                'open_id' => $account->open_id,
                'username' => $account->username,
                'display_name' => $account->display_name,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
            ]);
        }

        return response()->json([
            'status' => true,
            'tiktok_account_id' => $account->id,
            'open_id' => $account->open_id,
            'username' => $account->username,
            'display_name' => $account->display_name,
            'data' => $conversations,
        ]);
    }

    /**
     * View message history between the TikTok account and a customer.
     */
    public function messages(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'tiktok_account_id' => 'sometimes|integer|exists:tiktok_accounts,id',
            'open_id' => 'sometimes|string',
            'sender_id' => 'required|string',
            'search' => 'sometimes|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = $user->tiktokAccounts();
        if ($request->filled('tiktok_account_id')) {
            $query->where('id', $request->tiktok_account_id);
        } elseif ($request->filled('open_id')) {
            $query->where('open_id', $request->open_id);
        }

        /** @var TikTokAccount $account */
        $account = $query->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this TikTok account.',
            ], Response::HTTP_FORBIDDEN);
        }

        $senderId = (string) $request->sender_id;

        // Auto mark unread customer messages as read
        Chat::where('user_id', $user->id)
            ->where('tiktok_account_id', $account->id)
            ->where('tiktok_sender_id', $senderId)
            ->where('is_admin', false)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $query = Chat::where('user_id', $user->id)
            ->where('tiktok_account_id', $account->id)
            ->where('tiktok_sender_id', $senderId)
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
                'tiktok_account_id' => $account->id,
                'open_id' => $account->open_id,
                'username' => $account->username,
                'sender_id' => $senderId,
                'data' => $messages->items(),
                'pagination' => $this->extractPaginationMeta($messages),
            ]);
        }

        $messages = $query->get()->map($transform);

        return response()->json([
            'status' => true,
            'tiktok_account_id' => $account->id,
            'open_id' => $account->open_id,
            'username' => $account->username,
            'sender_id' => $senderId,
            'data' => $messages,
        ]);
    }

    /**
     * Send a manual reply to a customer via TikTok Direct Message API.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'tiktok_account_id' => 'sometimes|integer|exists:tiktok_accounts,id',
            'open_id' => 'sometimes|string',
            'recipient_id' => 'sometimes|string',
            'sender_id' => 'sometimes|string',
            'message' => 'required|string|max:4000',
        ]);

        $query = $user->tiktokAccounts()->where('status', 'active');
        if (! empty($validated['tiktok_account_id'])) {
            $query->where('id', $validated['tiktok_account_id']);
        } elseif (! empty($validated['open_id'])) {
            $query->where('open_id', $validated['open_id']);
        }

        /** @var TikTokAccount $account */
        $account = $query->firstOrFail();

        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription required or message quota exceeded for this TikTok account.',
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

        // Send via TikTok API
        $sendResult = $this->tiktokService->sendDirectMessage($account, $recipientId, $messageText);

        if (! $sendResult['success']) {
            return response()->json([
                'status' => false,
                'message' => $sendResult['message'] ?? 'Failed to send message via TikTok API.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Store Outgoing Agent Message
        $chat = Chat::create([
            'user_id' => $user->id,
            'tiktok_account_id' => $account->id,
            'tiktok_sender_id' => $recipientId,
            'name' => 'TikTok User',
            'channel' => 'tiktok',
            'message' => $messageText,
            'is_image' => false,
            'is_admin' => true,
            'is_read' => true,
            'read_at' => now(),
            'sender_type' => 'agent',
            'meta_message_id' => $sendResult['message_id'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'id' => $chat->id,
                'message' => $chat->message,
                'sender_type' => 'agent',
                'meta_message_id' => $chat->meta_message_id,
                'created_at' => $chat->created_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Manual pagination helper for collections.
     */
    protected function paginateCollection(Collection $items, int $perPage, int $page, Request $request): array
    {
        $total = $items->count();
        $sliced = $items->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $sliced,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return [
            'data' => $paginator->items(),
            'pagination' => $this->extractPaginationMeta($paginator),
        ];
    }

    /**
     * Extract consistent pagination metadata.
     */
    protected function extractPaginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
