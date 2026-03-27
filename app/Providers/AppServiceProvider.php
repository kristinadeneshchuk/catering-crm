<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Transaction;
use App\Models\Order;
use App\Models\StockDocumentItem;
use App\Observers\PaymentObserver;
use App\Observers\OrderObserver;
use App\Observers\StockDocumentItemObserver;

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

        // Після кожного надходження товару — оновлюємо кеш собівартості меню
        StockDocumentItem::observe(StockDocumentItemObserver::class);
    }
}