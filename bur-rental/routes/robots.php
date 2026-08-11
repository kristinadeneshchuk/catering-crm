<?php

use Illuminate\Support\Facades\Route;

/*
| robots.txt віддається кодом, а не статичним файлом: на тестовому майданчику
| він має закривати весь сайт, на бойовому — відкривати і вказувати на sitemap.
*/
Route::get('/robots.txt', function () {
    $body = config('app.noindex')
        ? "User-agent: *\nDisallow: /\n"
        : "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /booking\n";

    return response($body)->header('Content-Type', 'text/plain');
});
