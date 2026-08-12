<?php

use App\Http\Controllers\Api\Inbox\V1\ClientController;
use App\Http\Controllers\Api\Inbox\V1\OrderController;
use App\Http\Controllers\Api\Inbox\V1\ProjectController;
use App\Http\Controllers\Api\Inbox\V1\QuoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbox API v1
|--------------------------------------------------------------------------
|
| Інтеграція з зовнішньою системою листування (Telegram Inbox). Менеджер веде
| діалог у Telegram, а замовлення, клієнти і — головне — ціни живуть тут.
|
| Каскад: проєкт → тариф → діапазон калорій → дні → розрахунок → замовлення.
| Суму рахує тільки CRM, Inbox її ніколи не вигадує.
|
*/

Route::prefix('inbox/v1')
    ->middleware('inbox.token')
    ->group(function () {
        Route::get('projects', [ProjectController::class, 'index']);
        Route::get('projects/{project}/catalog', [ProjectController::class, 'catalog']);

        Route::post('quotes', QuoteController::class);

        Route::get('clients/search', [ClientController::class, 'search']);
        Route::get('clients/by-channel/{channel}/{externalId}', [ClientController::class, 'byChannel']);
        Route::post('clients', [ClientController::class, 'store']);
        Route::post('clients/{client}/channels', [ClientController::class, 'attachChannel']);
        Route::get('clients/{client}/orders', [ClientController::class, 'orders']);

        Route::post('orders', [OrderController::class, 'store']);
    });
