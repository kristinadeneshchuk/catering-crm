<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        /*
        | Обидві публічні форми пишуть у базу і смикають менеджера в Telegram,
        | тож ліміт тут не про навантаження, а про те, щоб скрипт не завалив
        | адмінку сотнею фальшивих броней. Межі свідомо високі: живий клієнт
        | у них не впирається навіть тоді, коли переоформлює замовлення втретє.
        */
        RateLimiter::for('booking', fn (Request $request) => Limit::perHour(20)
            ->by($request->ip())
            ->response(fn () => back()->withInput()->withErrors([
                'phone' => 'Забагато спроб бронювання. Спробуйте за годину або зателефонуйте нам.',
            ])));

        RateLimiter::for('leads', fn (Request $request) => Limit::perHour(10)
            ->by($request->ip())
            ->response(fn () => back()->withInput()->withErrors([
                'phone' => 'Забагато заявок з цієї адреси. Зателефонуйте нам — відповімо одразу.',
            ])));
    }
}
