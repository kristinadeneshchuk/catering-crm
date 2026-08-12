<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\ClientPaymentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\KitchenPlanController;
use App\Http\Controllers\PackagingAssemblyController;
use App\Http\Controllers\ClientMenuController;
use App\Http\Controllers\InstagramOAuthController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\Webhooks\InstagramWebhookController;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use App\Http\Controllers\Webhooks\ViberWebhookController;
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
 * 📜 ПУБЛІЧНІ ЮРИДИЧНІ СТОРІНКИ (для Meta App Review та клієнтів)
 */
Route::view('/privacy',       'legal.privacy')->name('legal.privacy');
Route::view('/terms',         'legal.terms')->name('legal.terms');
Route::view('/data-deletion', 'legal.data-deletion')->name('legal.data-deletion');

/**
 * 💬 МЕСЕНДЖЕР-ІНТЕГРАЦІЇ
 */

// Webhooks — викликаються самими месенджерами. CSRF вимкнено в bootstrap/app.php.
Route::post('/webhooks/viber/{account}', [ViberWebhookController::class, 'handle'])
    ->name('webhooks.viber');

Route::get('/webhooks/instagram',  [InstagramWebhookController::class, 'verify'])->name('webhooks.instagram.verify');
Route::post('/webhooks/instagram', [InstagramWebhookController::class, 'handle'])->name('webhooks.instagram');

// Рахунок відкривається без авторизації — посилання пересилають клієнту.
// Захист — непередбачуваний token, як у меню замовлення і кабінеті клієнта.
Route::get('/invoices/{token}.pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
Route::get('/invoices/{token}',     [InvoiceController::class, 'show'])->name('invoices.show');

// Telegram Business: підпису тіла немає, автентичність — по secret_token у заголовку.
Route::post('/webhooks/telegram/{account}', [TelegramWebhookController::class, 'handle'])
    ->name('webhooks.telegram');

// OAuth для Instagram (через Facebook Login).
// start — потребує авторизованого менеджера. callback — редирект з FB, без auth-middleware.
Route::middleware('auth')->group(function () {
    Route::get('/admin/messenger-accounts/{account}/oauth-instagram/start',
        [InstagramOAuthController::class, 'start'])
        ->name('messenger.instagram.oauth.start');
});

Route::get('/oauth/instagram/callback', [InstagramOAuthController::class, 'callback'])
    ->middleware('auth')
    ->name('messenger.instagram.oauth.callback');

/**
 * 📱 ПУБЛІЧНЕ МЕНЮ КЛІЄНТА (по QR-коду)
 */
Route::get('/menu-preview', [\App\Http\Controllers\MenuPreviewController::class, 'show'])->name('menu.preview');
// Постійне «Меню на сьогодні» для клієнтів (3 дні, КБЖУ+граммовка під калораж)
Route::get('/menu-today', [\App\Http\Controllers\PublicMenuController::class, 'today'])->name('menu.today');
Route::get('/menu/{token}', [ClientMenuController::class, 'show'])->name('menu.show');
Route::get('/menu/{token}/dish/{dishId}', [ClientMenuController::class, 'dish'])->name('menu.dish');
Route::post('/menu/{token}/rate', [ClientMenuController::class, 'rate'])->name('menu.rate');

/**
 * 👤 ОСОБИСТИЙ КАБІНЕТ КЛІЄНТА (по токену/QR, без пароля)
 */
Route::prefix('cabinet/{token}')->name('cabinet.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ClientCabinetController::class, 'overview'])->name('overview');
    Route::get('/orders', [\App\Http\Controllers\ClientCabinetController::class, 'orders'])->name('orders');
    Route::get('/payments', [\App\Http\Controllers\ClientCabinetController::class, 'payments'])->name('payments');
    Route::get('/deliveries', [\App\Http\Controllers\ClientCabinetController::class, 'deliveries'])->name('deliveries');
});

/**
 * 🖨️ БЛОК ДРУКУ
 */
Route::get('/print/stickers', [PrintController::class, 'stickers'])->name('print.stickers');
Route::get('/print/manifest', [PrintController::class, 'manifest'])->name('print.manifest');
Route::get('/print/packaging-list', [PrintController::class, 'packagingList'])->name('print.packaging-list');

Route::get('/print/logistics', [PrintController::class, 'logistics'])->name('print.logistics')->middleware('auth');
Route::get('/print/dish/{dishId}/tech-card', [PrintController::class, 'dishTechCard'])->name('print.dish.tech-card')->middleware('auth');
Route::get('/print/daily-menu/{dailyMenuId}/tech-cards', [PrintController::class, 'dailyMenuTechCards'])->name('print.daily-menu.tech-cards')->middleware('auth');

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
Route::get('/print/assembly-sheet', [\App\Http\Controllers\PrintController::class, 'assemblySheet'])->name('print.assembly-sheet');
Route::get('/print/cycle-menu', [PrintController::class, 'cycleMenu'])->name('print.cycle-menu');
Route::get('/print/repeat-clients', [PrintController::class, 'repeatClients'])->name('print.repeat-clients');
Route::get('/kitchen', [PrintController::class, 'kitchenMenu'])->name('kitchen.menu');

// 🔥 НОВИЙ МАРШРУТ АНАЛІТИКИ (закритий авторизацією)
Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/kitchen-plan', [KitchenPlanController::class, 'index'])->name('kitchen.plan');
    Route::post('/kitchen-plan/generate', [KitchenPlanController::class, 'generate'])->name('kitchen.plan.generate');
    Route::get('/kitchen-plan/status', [KitchenPlanController::class, 'status'])->name('kitchen.plan.status');

    // Список пакування на день для менеджера
    Route::get('/packaging-assembly', [PackagingAssemblyController::class, 'index'])->name('packaging.assembly');
});