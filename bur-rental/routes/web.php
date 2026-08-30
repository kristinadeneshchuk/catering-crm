<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CabinetController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\FavouriteController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KitController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Кожна дія на сайті — реальний маршрут. href="#" у продакшені не буває:
| це помилка конкурента, яку ми не повторюємо.
*/

require __DIR__.'/robots.php';

Route::get('/', HomeController::class)->name('home');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{category}', [CatalogController::class, 'show'])->name('category');

Route::get('/instrument/{product}', [ProductController::class, 'show'])->name('product');

Route::get('/kits', [KitController::class, 'index'])->name('kits.index');
Route::get('/kits/{kit}', [KitController::class, 'show'])->name('kit');

Route::get('/brand/{brand}', BrandController::class)->name('brand');

Route::get('/booking', [BookingController::class, 'create'])->name('booking.create');
Route::post('/booking', [BookingController::class, 'store'])
    ->middleware('throttle:booking')->name('booking.store');
Route::get('/booking/{booking}', [BookingController::class, 'show'])->name('booking.show');

Route::get('/blog', [ArticleController::class, 'index'])->name('blog.index');
Route::get('/blog/{article}', [ArticleController::class, 'show'])->name('article');

Route::get('/search', SearchController::class)->name('search');

Route::get('/terms', [PageController::class, 'terms'])->name('terms');
Route::get('/delivery', [PageController::class, 'delivery'])->name('delivery');
Route::get('/returns', [PageController::class, 'returns'])->name('returns');
Route::get('/contacts', [PageController::class, 'contacts'])->name('contacts');
Route::get('/b2b', [PageController::class, 'b2b'])->name('b2b');

Route::post('/leads', [LeadController::class, 'store'])
    ->middleware('throttle:leads')->name('leads.store');

/*
| Кабінет клієнта. Guard `client` — не `web`: у `web` сидять співробітники
| з доступом до Filament, і плутати ці дві ролі не можна.
*/
Route::get('/favourites', [FavouriteController::class, 'index'])->name('favourites');
Route::get('/favourites/items', [FavouriteController::class, 'items'])->name('favourites.items');
// Прив'язка по id, а не по slug: у localStorage лежать саме id.
Route::post('/favourites/{product:id}', [FavouriteController::class, 'toggle'])->name('favourites.toggle');
Route::post('/favourites-sync', [FavouriteController::class, 'sync'])->name('favourites.sync');

Route::middleware('guest:client')->group(function () {
    Route::get('/cabinet/login', [ClientAuthController::class, 'form'])->name('cabinet.login');
    Route::post('/cabinet/login', [ClientAuthController::class, 'requestCode'])
        ->middleware('throttle:login-code')->name('cabinet.request-code');
    Route::get('/cabinet/code', [ClientAuthController::class, 'codeForm'])->name('cabinet.code');
    Route::post('/cabinet/code', [ClientAuthController::class, 'login'])
        ->middleware('throttle:login-code')->name('cabinet.verify');
});

Route::middleware('auth:client')->group(function () {
    Route::get('/cabinet', [CabinetController::class, 'index'])->name('cabinet');
    Route::get('/cabinet/profile', [CabinetController::class, 'profile'])->name('cabinet.profile');
    Route::put('/cabinet/profile', [CabinetController::class, 'updateProfile'])->name('cabinet.profile.update');
    Route::get('/cabinet/booking/{booking}', [CabinetController::class, 'booking'])->name('cabinet.booking');
    Route::post('/cabinet/logout', [ClientAuthController::class, 'logout'])->name('cabinet.logout');
});

/*
| Гео-маршрути йдуть останніми і жорстко обмежені slug'ами міст —
| інакше /catalog з'їдається як «місто».
*/
$cities = 'kyiv|kharkiv|lviv';

Route::get('/{city}', CityController::class)->whereIn('city', explode('|', $cities))->name('city');
// scopeBindings: філія шукається серед філій цього міста, а не по всій базі —
// slug'и філій унікальні лише в межах міста.
Route::get('/{city}/branches/{branch}', BranchController::class)
    ->whereIn('city', explode('|', $cities))->scopeBindings()->name('branch');
Route::get('/{city}/{district}', DistrictController::class)
    ->whereIn('city', explode('|', $cities))->scopeBindings()->name('district');
