<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Transaction;          
use App\Observers\PaymentObserver;

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
        // Кажемо Laravel: "Слідкуй за ТРАНЗАКЦІЯМИ через цей клас"
        Transaction::observe(PaymentObserver::class); 
    }
}