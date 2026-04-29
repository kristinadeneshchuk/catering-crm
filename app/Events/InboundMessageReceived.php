<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Подія "прийшло нове повідомлення".
 * Транслюється на публічний канал messenger-inbox.
 * Payload містить тільки conversation_id — UI сам перезавантажить дані з БД.
 * Це навмисно: щоб не лити секрети на pusher-канал.
 */
class InboundMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('messenger-inbox');
    }

    public function broadcastAs(): string
    {
        return 'MessageReceived';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
        ];
    }
}
