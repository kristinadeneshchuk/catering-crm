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


Route::get('/make-it-short', function () {
    // Міняємо і "ПФ", і "Напівфабрикати" на коротке "НФ"
    $updates1 = \App\Models\Dish::where('group', 'Напівфабрикати')->update(['group' => 'НФ']);
    $updates2 = \App\Models\Dish::where('group', 'ПФ')->update(['group' => 'НФ']);
    
    return "Готово! Оновлено записів: " . ($updates1 + $updates2) . ". Тепер скрізь 'НФ'.";
});