<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Чистимо активити-лог: записи старші за clean_after_days (180д = 6 міс).
Schedule::command('activitylog:clean')->dailyAt('03:30');

// Архів маршрутів. О 11:00 учорашній день уже точно розвезений і в ANT його
// ніхто не перебудовує — знімаємо його разом з точками. Це страховка: коли
// менеджер тисне «Точки маршрутів» вручну, архів пишеться одразу.
Schedule::command('routes:snapshot')->dailyAt('11:00')->withoutOverlapping();

// Звіт зміни курʼєра в Telegram: шаблон на початку зміни, нагадування в кінці —
// лише тим, хто ще не здав. Шлемо тільки курʼєрам, які самі підключились до
// бота, тож поки ніхто не підключений, ці задачі нікого не турбують.
// Ранкові маршрути стартують близько 06:00, вечірні — близько 17:30.
Schedule::command('couriers:shift-report morning')->dailyAt(config('services.telegram.report_morning_at', '05:45'));
Schedule::command('couriers:shift-report morning --remind')->dailyAt(config('services.telegram.report_morning_remind_at', '12:30'));
Schedule::command('couriers:shift-report evening')->dailyAt(config('services.telegram.report_evening_at', '17:00'));
Schedule::command('couriers:shift-report evening --remind')->dailyAt(config('services.telegram.report_evening_remind_at', '22:00'));

// Telegram аналітика
Schedule::command('telegram:morning-pulse')->dailyAt('11:00');
Schedule::command('telegram:evening-summary')->dailyAt('18:00');
Schedule::command('telegram:weekly-digest')->weeklyOn(1, '09:00'); // 1 = понеділок
Schedule::command('telegram:kitchen-daily-summary')->dailyAt('20:00');

// Instagram polling — для Message Requests (перших звернень нових клієнтів),
// які не приходять через webhook навіть після публікації app.
// Увімкнути ПІСЛЯ App Review (зараз у dev mode conversations API повертає порожньо).
// Schedule::command('messenger:poll-instagram')->everyMinute()->withoutOverlapping();
