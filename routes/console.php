<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull route assignments back from Ant at 22:00 (after dispatcher builds routes)
Schedule::command('ant:pull-routes')->dailyAt('22:00');

// Telegram аналітика
Schedule::command('telegram:morning-pulse')->dailyAt('11:00');
Schedule::command('telegram:evening-summary')->dailyAt('18:00');
Schedule::command('telegram:weekly-digest')->weeklyOn(1, '09:00'); // 1 = понеділок

// Instagram polling — workaround для dev mode і для Message Requests
Schedule::command('messenger:poll-instagram')->everyMinute()->withoutOverlapping();
