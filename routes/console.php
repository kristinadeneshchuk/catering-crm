<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Push tomorrow's orders to Ant Logistics every evening at 21:00
Schedule::command('ant:push-orders')->dailyAt('21:00');

// Pull route assignments back from Ant at 22:00 (after dispatcher builds routes)
Schedule::command('ant:pull-routes')->dailyAt('22:00');
