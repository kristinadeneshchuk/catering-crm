<?php

namespace App\Livewire;

use App\Models\KitchenNotification;
use Livewire\Component;
use Livewire\Attributes\On;

class KitchenNotificationBell extends Component
{
    public int $unreadCount = 0;
    public array $latestToasts = [];

    public function mount(): void
    {
        $this->unreadCount = KitchenNotification::unreadCount();
    }

    // Called by JS when Pusher delivers a new event
    #[On('kitchen-notification-received')]
    public function handleNewNotification(array $data): void
    {
        $this->unreadCount = KitchenNotification::unreadCount();

        // Add toast (keep max 3)
        array_unshift($this->latestToasts, $data);
        $this->latestToasts = array_slice($this->latestToasts, 0, 3);
    }

    public function dismissToast(int $index): void
    {
        array_splice($this->latestToasts, $index, 1);
    }

    public function render()
    {
        return view('livewire.kitchen-notification-bell');
    }
}
