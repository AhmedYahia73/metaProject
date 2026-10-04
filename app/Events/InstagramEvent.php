<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InstagramEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $chat;

    public function __construct(array $chat)
    {
        $this->chat = $chat;
        Log::info('🎯 [Instagram] New Chat', [
            'instagram_sender_id' => $chat['instagram_sender_id'] ?? null,
            'instagram_id' => $chat['instagram_id'] ?? null,
        ]);
    }

    public function broadcastOn(): array
    {
        $senderId = $this->chat['instagram_sender_id'] ?? '';
        $instagramId = $this->chat['instagram_id'] ?? '';

        return [
            new Channel('userInsta_'.$senderId.'_'.$instagramId),
            new Channel('userInsta_'.$senderId),
            new Channel('userInsta_'),
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
            'instagram_sender_id' => $this->chat['instagram_sender_id'] ?? null,
            'instagram_id' => $this->chat['instagram_id'] ?? null,
            'message' => $this->chat['message'] ?? '',
            'sender_type' => $this->chat['sender_type'] ?? 'customer',
            'is_admin' => $this->chat['is_admin'] ?? false,
            'channel' => 'instagram',
            'created_at' => $createdAt,
        ];
    }
}
