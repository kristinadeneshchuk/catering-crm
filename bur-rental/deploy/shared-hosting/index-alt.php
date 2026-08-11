<?php

/*
 | Варіант index.php для хостингу, де не можна змінити корінь сайту.
 |
 | Кладеться в public_html разом з рештою вмісту папки public/, тоді як сам
 | застосунок лежить рівнем вище — у ~/bur_app. Якщо панель дозволяє вказати
 | корінь сайту на ~/bur_app/public, цей файл не потрібен.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app_path = __DIR__.'/../bur_app';

if (file_exists($maintenance = $app_path.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_path.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_path.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
