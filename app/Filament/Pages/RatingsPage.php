<?php

namespace App\Filament\Pages;

use App\Models\DishRating;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class RatingsPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-star';
    protected static ?string $navigationLabel = 'Відгуки та рейтинги';
    protected static ?string $title           = 'Відгуки та рейтинги';
    protected static string  $view            = 'filament.pages.ratings';
    protected static ?int    $navigationSort  = 4;

    public string $filterDish   = '';
    public string $filterClient = '';
    public string $filterStars  = '';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager'], true);
    }

    // ── Відгуки з фільтрами ──
    public function getRatingsProperty()
    {
        $query = DishRating::with(['order.client', 'dish'])
            ->orderBy('created_at', 'desc');

        if ($this->filterStars) {
            $query->where('stars', $this->filterStars);
        }

        if ($this->filterDish) {
            $query->whereHas('dish', fn($q) => $q->where('name', 'like', '%' . $this->filterDish . '%'));
        }

        if ($this->filterClient) {
            $query->whereHas('order.client', fn($q) => $q->where('name', 'like', '%' . $this->filterClient . '%'));
        }

        return $query->paginate(30);
    }

    // ── Середні оцінки по стравах ──
    public function getDishStatsProperty()
    {
        return DishRating::select('dish_id', DB::raw('AVG(stars) as avg_stars'), DB::raw('COUNT(*) as total'))
            ->with('dish:id,name')
            ->groupBy('dish_id')
            ->orderByDesc('avg_stars')
            ->get();
    }

    // ── Замовлення з розблокованою нагородою (ще не видано) ──
    public function getPendingRewardsProperty()
    {
        return Order::where('reward_unlocked', true)
            ->where('reward_given', false)
            ->with(['client', 'tariff'])
            ->withCount('dishRatings')
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    // ── Відмітити нагороду як видану ──
    public function giveReward(int $orderId): void
    {
        $order = Order::findOrFail($orderId);
        $order->update(['reward_given' => true]);

        Notification::make()
            ->title('Нагороду відмічено як видану')
            ->body("Клієнт {$order->client->name}")
            ->success()
            ->send();
    }

    // ── Загальна статистика ──
    public function getOverallStatsProperty(): array
    {
        return [
            'total'   => DishRating::count(),
            'avg'     => round(DishRating::avg('stars'), 1),
            'pending' => Order::where('reward_unlocked', true)->where('reward_given', false)->count(),
            'given'   => Order::where('reward_given', true)->count(),
        ];
    }
}
