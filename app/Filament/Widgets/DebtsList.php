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
        // Борг = від'ємний баланс клієнта (джерело правди, його веде Client::syncBalance()).
        // НЕ покладаємось на прапорець is_paid окремих замовлень — він буває застарілий
        // і не ловить боржників із завершеними замовленнями.
        $rows = \App\Models\Client::query()
            ->where('balance', '<', 0)
            ->with(['orders' => fn ($q) => $q->orderByDesc('start_date')->orderByDesc('id')])
            ->get()
            ->map(function ($client) {
                $orders = $client->orders;
                // Контекстне замовлення: спершу активне/нове, інакше — останнє
                $order = $orders->firstWhere('status', 'active')
                      ?? $orders->firstWhere('status', 'new')
                      ?? $orders->first();
                $isActive = $order && in_array($order->status, ['active', 'new'], true);

                return [
                    'order_id'    => $order?->id,
                    'client_id'   => $client->id,
                    'client_name' => $client->name ?? '—',
                    'client_url'  => ClientResource::getUrl('edit', ['record' => $client->id]),
                    'start_date'  => $order?->start_date?->format('d.m.Y') ?? '—',
                    'end_date'    => $order?->end_date?->format('d.m.Y') ?? '—',
                    'start_raw'   => $order?->start_date?->timestamp ?? 0,
                    'duration'    => $order?->duration ?? 0,
                    'due'         => -(float) $client->balance,           // сам борг (= скільки винні)
                    'status'      => $isActive ? $order->status : 'finished',
                ];
            });

        // Сортування рядків
        $sortKey = match ($this->sortBy) {
            'final_price' => 'due',
            'start_date'  => 'start_raw',
            'status'      => 'status',
            default       => 'client_name',
        };
        $rows = $this->sortDir === 'desc'
            ? $rows->sortByDesc($sortKey)->values()
            : $rows->sortBy($sortKey)->values();

        return [
            'debtsList' => $rows,
            'sortBy'    => $this->sortBy,
            'sortDir'   => $this->sortDir,
        ];
    }
}
