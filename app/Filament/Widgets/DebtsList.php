<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Filament\Resources\ClientResource;
use Filament\Widgets\Widget;

class DebtsList extends Widget
{
    protected static string $view = 'filament.widgets.debts-list';
    protected static ?int $sort = 4;
    protected int | string | array $columnSpan = 'full';

    // Сортування
    public string $sortBy  = 'start_date';
    public string $sortDir = 'asc';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager']);
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $column;
            $this->sortDir = 'asc';
        }
    }

    protected function getViewData(): array
    {
        $orders = Order::with('client')
            ->where('is_paid', false)
            ->whereIn('status', ['active', 'new'])
            ->where(function ($q) {
                $q->where('final_price', '>', 0)
                  ->orWhere('total_price', '>', 0);
            })
            ->orderBy($this->sortBy, $this->sortDir)
            ->get()
            ->map(function ($order) {
                $due = (float) ($order->final_price > 0 ? $order->final_price : $order->total_price);
                return [
                    'order_id'    => $order->id,
                    'client_id'   => $order->client_id,
                    'client_name' => $order->client?->name ?? '—',
                    'client_url'  => $order->client_id
                        ? ClientResource::getUrl('edit', ['record' => $order->client_id])
                        : null,
                    'start_date'  => $order->start_date?->format('d.m.Y') ?? '—',
                    'end_date'    => $order->end_date?->format('d.m.Y') ?? '—',
                    'start_raw'   => $order->start_date?->timestamp ?? 0,
                    'duration'    => $order->duration,
                    'due'         => $due,
                    'status'      => $order->status,
                ];
            });

        return [
            'debtsList' => $orders,
            'sortBy'    => $this->sortBy,
            'sortDir'   => $this->sortDir,
        ];
    }
}
