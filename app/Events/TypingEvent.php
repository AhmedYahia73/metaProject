<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TypingEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $channel  'whatsapp' | 'messenger'
     * @param  string|null  $phone  WhatsApp sender phone (null for Messenger)
     * @param  string|null  $senderId  Messenger PSID (null for WhatsApp)
     * @param  string|null  $pageId  Messenger page_id (null for WhatsApp)
     * @param  bool  $isTyping  true = typing_on, false = typing_off
     */
    public function __construct(
        public readonly string $channel,
        public readonly ?string $phone,
        public readonly ?string $senderId,
        public readonly ?string $pageId,
        public readonly bool $isTyping = true,
    ) {}

    public function broadcastOn(): array
    {
        if ($this->channel === 'whatsapp' && $this->phone) {
            return [
                new Channel('userWhats_'.$this->phone),
                new Channel('userWhats_'),
            ];
        }

        if (in_array($this->channel, ['messenger', 'instagram'], true) && $this->senderId) {
            return [
                new Channel('userChat_'.$this->senderId.'_'.($this->pageId ?? '')),
                new Channel('userChat_'.$this->senderId),
                new Channel('userChat_'),
            ];
        }

        return [new Channel('userWhats_')];
    }

    public function broadcastAs(): string
    {
        return 'TypingEvent';
    }

    public function broadcastWith(): array
    {
        return [
            'channel' => $this->channel,
            'phone' => $this->phone,
            'sender_id' => $this->senderId,
            'page_id' => $this->pageId,
            'is_typing' => $this->isTyping,
        ];
    }
}
