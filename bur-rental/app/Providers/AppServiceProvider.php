<?php

namespace App\Providers;

use App\Services\Clients\CodeSender;
use App\Support\Phone;
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
        $this->app->bind(CodeSender::class, fn () => $this->app->make(config('clients.code_sender')));
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

        /*
        | Коди входу — найдорожча форма на сайті: кожен запит це SMS за гроші,
        | а перебір коду відкриває чужу історію замовлень. Рахуємо по IP і по
        | номеру окремо, щоб ні один IP не міг молотити по різних номерах, ні
        | різні IP — по одному номеру.
        */
        RateLimiter::for('login-code', function (Request $request) {
            // На кроці з кодом номера у формі немає — він лежить у сесії.
            $phone = Phone::normalize($request->input('phone'))
                ?? $request->session()->get('login.phone')
                ?? $request->ip();

            return [
                Limit::perHour(10)->by('ip:'.$request->ip()),
                Limit::perHour(5)->by('phone:'.$phone),
            ];
        });

        RateLimiter::for('leads', fn (Request $request) => Limit::perHour(10)
            ->by($request->ip())
            ->response(fn () => back()->withInput()->withErrors([
                'phone' => 'Забагато заявок з цієї адреси. Зателефонуйте нам — відповімо одразу.',
            ])));
    }
}
