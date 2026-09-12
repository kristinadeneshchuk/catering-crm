<?php

use App\Http\Controllers\Api\Inbox\V1\ClientController;
use App\Http\Controllers\Api\Inbox\V1\InvoiceController;
use App\Http\Controllers\Api\Inbox\V1\OrderController;
use App\Http\Controllers\Api\Inbox\V1\PaymentClaimController;
use App\Http\Controllers\Api\Inbox\V1\ProjectController;
use App\Http\Controllers\Api\Inbox\V1\QuoteController;
use App\Http\Controllers\Api\Lunch\LunchApiController;
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
        Route::post('orders/{order}/invoice', [InvoiceController::class, 'store']);

        // Заяви про оплату: агент лише фіксує слова клієнта. Ставити оплату він не
        // може — це робить менеджер у CRM.
        Route::post('orders/{order}/payment-claims', [PaymentClaimController::class, 'store']);
        Route::get('orders/{order}/payment', [PaymentClaimController::class, 'show']);
    });

/*
|--------------------------------------------------------------------------
| Lunch API
|--------------------------------------------------------------------------
|
| Міст до Lunch Hub — сервісу корпоративних обідів. Він тримає власні техкартки,
| але рахує їх з наших закупівельних цін, тож тягне звідси каталог страв і
| довідник інгредієнтів. Назад віддає зведення «скільки чого готувати».
|
| Тільки читання; зведення лягає у файл, не в базу.
|
*/

Route::prefix('lunch')
    ->middleware('lunch.token')
    ->group(function () {
        Route::get('dishes', [LunchApiController::class, 'dishes']);
        Route::get('ingredients', [LunchApiController::class, 'ingredients']);
        Route::post('kitchen-plan', [LunchApiController::class, 'kitchenPlan']);
    });
