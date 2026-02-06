<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Transaction;          
use App\Models\Order;                // 1. Додали модель Order
use App\Observers\PaymentObserver;
use App\Observers\OrderObserver;     // 2. Додали OrderObserver

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

        // 3. ДОДАЄМО: Слідкуємо за замовленнями (статуси new/active)
        Order::observe(OrderObserver::class);
    }
}