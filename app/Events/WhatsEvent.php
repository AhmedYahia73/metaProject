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
        $this->chat = $chat;
        Log::info('🎯 New Chat', ['phone' => $chat['phone']]);
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('userWhats_'.$this->chat['phone']),
            new Channel('userWhats_'),
        ];
    }

    public function broadcastAs(): string
    {
        Log::info('📢 Broadcast As: NewchatEvent');

        return 'UserChatEvent';
    }

    public function broadcastWith(): array
    {
        $data = [
            'phone' => $this->chat['phone'],
            'message' => $this->chat['message'],
            'created_at' => $this->chat['created_at']?->format('Y-m-d H:i:s'),
        ];

        Log::info('📦 Broadcasting Data:', $data);

        return $data;
    }
}
