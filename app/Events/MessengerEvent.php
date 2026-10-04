<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MessengerEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $chat;

    public function __construct(array $chat)
    {
        $this->chat = $chat;
        Log::info('🎯 [Messenger] New Chat', [
            'messenger_sender_id' => $chat['messenger_sender_id'] ?? null,
            'page_id' => $chat['page_id'] ?? null,
        ]);
    }

    public function broadcastOn(): array
    {
        $senderId = $this->chat['messenger_sender_id'] ?? '';
        $pageId = $this->chat['page_id'] ?? '';

        return [
            new Channel('userChat_'.$senderId.'_'.$pageId),
            new Channel('userChat_'.$senderId),
            new Channel('userChat_'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'UserChatEvent';
    }

    public function broadcastWith(): array
    {
        $createdAt = $this->chat['created_at'] ?? null;
        if ($createdAt instanceof \DateTimeInterface) {
            $createdAt = $createdAt->format('Y-m-d H:i:s');
        }

        return [
            'id' => $this->chat['id'] ?? null,
            'name' => $this->chat['name'] ?? null,
            'messenger_sender_id' => $this->chat['messenger_sender_id'] ?? null,
            'page_id' => $this->chat['page_id'] ?? null,
            'message' => $this->chat['message'] ?? '',
            'sender_type' => $this->chat['sender_type'] ?? 'customer',
            'is_admin' => $this->chat['is_admin'] ?? false,
            'channel' => 'messenger',
            'created_at' => $createdAt,
        ];
    }
}
