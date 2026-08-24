<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

/*
 | Одноразовий запуск міграцій і сидів на хостингу без SSH.
 |
 | Кладеться в корінь сайту, відкривається один раз із токеном:
 |     https://домен/setup.php?token=<SETUP_TOKEN з .env>
 |
 | Після успіху ВИДАЛЯЄ САМ СЕБЕ. Без токена або з чужим токеном не робить
 | нічого — інакше будь-хто зміг би перезаписати вашу базу.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$expected = env('SETUP_TOKEN');
$given = $_GET['token'] ?? '';

header('Content-Type: text/plain; charset=utf-8');

if (! $expected || ! hash_equals($expected, $given)) {
    http_response_code(403);
    exit("Невірний токен.\n");
}

$fresh = isset($_GET['fresh']);

// migrate:fresh стирає дані — тільки за явним проханням в адресі.
$commands = $fresh
    ? [['migrate:fresh', ['--force' => true, '--seed' => true]]]
    : [['migrate', ['--force' => true]], ['db:seed', ['--force' => true]]];

foreach ($commands as [$command, $options]) {
    echo "→ php artisan {$command}\n";
    Artisan::call($command, $options);
    echo Artisan::output()."\n";
}

// Сиди наповнюють пошуковий рядок самі, але міграція на вже наявну базу — ні.
foreach (['search:reindex', 'storage:link', 'config:cache', 'view:cache', 'event:cache'] as $command) {
    echo "→ php artisan {$command}\n";
    try {
        Artisan::call($command);
        echo Artisan::output()."\n";
    } catch (Throwable $e) {
        echo "  пропущено: {$e->getMessage()}\n";
    }
}

// Слід за собою прибираємо: лишити цей файл на сайті не можна.
if (@unlink(__FILE__)) {
    echo "\n✓ Готово. setup.php видалено.\n";
} else {
    echo "\n⚠ Готово, але setup.php НЕ видалився — зітріть його вручну через файловий менеджер.\n";
}

echo "Адмінка: /admin\n";
