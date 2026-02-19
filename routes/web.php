<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\ClientPaymentController;
// !!! ОСЬ ЦІ ДВА РЯДКИ КРИТИЧНО ВАЖЛИВІ !!!
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
 * 🖨️ БЛОК ДРУКУ
 */
Route::get('/print/stickers', [PrintController::class, 'stickers'])->name('print.stickers');
Route::get('/print/manifest', [PrintController::class, 'manifest'])->name('print.manifest');
Route::get('/print/packaging-list', [PrintController::class, 'packagingList'])->name('print.packaging-list');

// === 🚚 ЕКСПОРТ В EXCEL ===
Route::get('/print/logistics', function (Request $request) {
    // 1. Отримуємо дату
    $dateStr = $request->input('date', now()->format('Y-m-d'));
    
    // 2. Формуємо назву файлу: logistics_export_2026-02-03_15-30-00.xlsx
    $fileName = 'logistics_export_' . now()->format('Y-m-d_H-i-s') . '.xlsx';

    // 3. Скачуємо файл
    return Excel::download(new LogisticsExport($dateStr), $fileName);

})->name('print.logistics')->middleware('auth');

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
Route::get('/print/mini-manifest', [\App\Http\Controllers\PrintController::class, 'miniManifest'])->name('print.mini-manifest');