<?php

namespace App\Events;

use App\Models\KitchenNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KitchenOrderEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $data;

    public function __construct(KitchenNotification $notification)
    {
        $this->data = [
            'id'            => $notification->id,
            'type'          => $notification->type,
            'client_name'   => $notification->client_name,
            'calories'      => $notification->calories,
            'schedule_type' => $notification->schedule_type,
            'project'       => $notification->project,
            'has_exclusions'=> $notification->has_exclusions,
            'duration'      => $notification->duration,
            'start_date'    => $notification->start_date?->format('d.m.Y'),
            'message'       => $notification->message,
        ];
    }

    public function broadcastOn(): Channel
    {
        return new Channel('kitchen');
    }

    public function broadcastAs(): string
    {
        return 'order.notification';
    }
}
