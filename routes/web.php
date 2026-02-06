<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\ClientPaymentController;
// !!! ОСЬ ЦІ ДВА РЯДКИ КРИТИЧНО ВАЖЛИВІ !!!
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\LogisticsExport;

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

// Маршрут для закриття старих замовлень
Route::get('/update-statuses', function () {
    // Шукаємо замовлення, де дата закінчення вже пройшла (менше сьогодні)
    // І які ще НЕ завершені
    $expiredOrders = \App\Models\Order::whereDate('end_date', '<', now())
        ->whereNotIn('status', ['finished', 'completed']) // Не чіпаємо вже закриті
        ->get();

    $count = 0;
    foreach ($expiredOrders as $order) {
        // ВАЖЛИВО: Якщо замовлення на ПАУЗІ, ми його не чіпаємо (як ви і просили)
        if ($order->status === 'paused') {
            continue;
        }

        // Всі інші (new, active) переводимо в finished
        $order->update(['status' => 'finished']);
        $count++;
    }

    return "Готово! Автоматично завершено замовлень: {$count}";
});
