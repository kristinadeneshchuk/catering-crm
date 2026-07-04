<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Transaction;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\DeliveryRoute;
use App\Models\StockDocumentItem;
use App\Observers\PaymentObserver;
use App\Observers\OrderObserver;
use App\Observers\OrderDayObserver;
use App\Observers\DeliveryRouteObserver;
use App\Observers\StockDocumentItemObserver;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Слідкуємо за фінансами
        Transaction::observe(PaymentObserver::class); 

        Order::observe(OrderObserver::class);

        // Auto-перерахунок статусу замовлення при будь-якій зміні order_days
        OrderDay::observe(OrderDayObserver::class);

        // Після кожного надходження товару — оновлюємо кеш собівартості меню
        StockDocumentItem::observe(StockDocumentItemObserver::class);

        // Маршрут курʼєра створився/змінився з ANT → переоцінюємо ставку зміни у Табелі
        DeliveryRoute::observe(DeliveryRouteObserver::class);

        // Kitchen notification bell — show for all roles in top bar
        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
            fn (): string => auth()->check()
                ? Blade::render('<livewire:kitchen-notification-bell />')
                : '',
        );
    }
}