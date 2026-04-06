<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\ClientPaymentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\KitchenPlanController;
use App\Http\Controllers\ClientMenuController;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\LogisticsExport;
use App\Models\Order;
use App\Models\OrderDay;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

/**
 * 📱 ПУБЛІЧНЕ МЕНЮ КЛІЄНТА (по QR-коду)
 */
Route::get('/menu/{token}', [ClientMenuController::class, 'show'])->name('menu.show');
Route::get('/menu/{token}/dish/{dishId}', [ClientMenuController::class, 'dish'])->name('menu.dish');

/**
 * 🖨️ БЛОК ДРУКУ
 */
Route::get('/print/stickers', [PrintController::class, 'stickers'])->name('print.stickers');
Route::get('/print/manifest', [PrintController::class, 'manifest'])->name('print.manifest');
Route::get('/print/packaging-list', [PrintController::class, 'packagingList'])->name('print.packaging-list');

Route::get('/print/logistics', [PrintController::class, 'logistics'])->name('print.logistics')->middleware('auth');

/**
 * 🔐 ОСОБИСТИЙ КАБІНЕТ КЛІЄНТА
 */
Route::prefix('client')->group(function () {
    Route::get('/login', [ClientAuthController::class, 'showLoginForm'])->name('client.login');
    Route::post('/login', [ClientAuthController::class, 'login'])->name('client.login.submit');
    Route::post('/logout', [ClientAuthController::class, 'logout'])->name('client.logout');

    Route::middleware('auth:client')->group(function () {
        Route::get('/dashboard', function () {
            $client = auth()->guard('client')->user();
            $orders = $client->orders()->with('tariff')->orderBy('id', 'desc')->get();
            return view('client.dashboard', compact('client', 'orders'));
        })->name('client.dashboard');

        Route::post('/order/{order}/pay', [ClientPaymentController::class, 'pay'])->name('client.order.pay');
    });
});

Route::get('/migrate-orders-to-days', function () {
    $orders = Order::whereNotIn('status', ['completed', 'finished'])->get(); // Беремо тільки активні/нові
    $count = 0;

    foreach ($orders as $order) {
        if (!$order->start_date || !$order->end_date) continue;

        $current = Carbon::parse($order->start_date);
        $end = Carbon::parse($order->end_date);

        while ($current->lte($end)) {
            // Створюємо день, якщо його ще немає
            OrderDay::firstOrCreate([
                'order_id' => $order->id,
                'date' => $current->format('Y-m-d')
            ]);
            
            $current->addDay();
            $count++;
        }
    }

    return "Успішно! Ми перетворили старі дати на {$count} окремих записів у календарі.";
});

Route::get('/print/production-report', [PrintController::class, 'productionReport'])->name('print.production-report');
Route::get('/print/stock-list', [PrintController::class, 'stockList'])->name('print.stock-list');
Route::get('/print/shopping-list', [PrintController::class, 'shoppingList'])->name('print.shopping-list');
Route::get('/print/mini-manifest', [\App\Http\Controllers\PrintController::class, 'miniManifest'])->name('print.mini-manifest');
Route::get('/print/cycle-menu', [PrintController::class, 'cycleMenu'])->name('print.cycle-menu');

// 🔥 НОВИЙ МАРШРУТ АНАЛІТИКИ (закритий авторизацією)
Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/kitchen-plan', [KitchenPlanController::class, 'index'])->name('kitchen.plan');
    Route::post('/kitchen-plan/generate', [KitchenPlanController::class, 'generate'])->name('kitchen.plan.generate');
    Route::get('/kitchen-plan/status', [KitchenPlanController::class, 'status'])->name('kitchen.plan.status');
});