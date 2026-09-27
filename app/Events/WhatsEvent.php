<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

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
            new Channel('userChat_' . $this->chat['phone']), 
            new Channel('userChat_'), 
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
