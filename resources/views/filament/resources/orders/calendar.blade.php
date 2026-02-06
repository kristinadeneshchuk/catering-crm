<div class="p-4">
    @livewire('order-calendar', [
        'startDateStr' => $order->start_date,
        'duration' => (int) $order->duration ?: 1,
        'scheduleType' => $order->schedule_type,
    ])
</div>