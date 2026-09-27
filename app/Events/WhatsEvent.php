<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WhatsEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;

    public function __construct($chat)
    {
        $this->chat = is_array($chat) ? $chat : $chat->toArray();
        Log::info('🎯 New Chat', ['phone' => $this->chat['phone'] ?? 'messenger']);
    }

    public function broadcastOn(): array
    {
        $phone = $this->chat['phone'] ?? null;

        return array_filter([
            $phone ? new Channel('userWhats_'.$phone) : null,
            new Channel('userWhats_'),
        ]);
    }

    public function broadcastAs(): string
    {
        Log::info('📢 Broadcast As: NewchatEvent');

        return 'UserChatEvent';
    }

    public function broadcastWith(): array
    {
        $createdAt = $this->chat['created_at'] ?? null;

        if ($createdAt instanceof \DateTimeInterface) {
            $createdAt = $createdAt->format('Y-m-d H:i:s');
        }

        $data = [
            'phone' => $this->chat['phone'] ?? null,
            'message' => $this->chat['message'] ?? '',
            'created_at' => $createdAt,
        ];

        Log::info('📦 Broadcasting Data:', $data);

        return $data;
    }
}
