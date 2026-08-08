<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\DistrictController;
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

Route::get('/', HomeController::class)->name('home');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{category}', [CatalogController::class, 'show'])->name('category');

Route::get('/instrument/{product}', [ProductController::class, 'show'])->name('product');

Route::get('/kits', [KitController::class, 'index'])->name('kits.index');
Route::get('/kits/{kit}', [KitController::class, 'show'])->name('kit');

Route::get('/brand/{brand}', BrandController::class)->name('brand');

Route::get('/booking', [BookingController::class, 'create'])->name('booking.create');
Route::post('/booking', [BookingController::class, 'store'])->name('booking.store');
Route::get('/booking/{booking}', [BookingController::class, 'show'])->name('booking.show');

Route::get('/search', SearchController::class)->name('search');

Route::get('/terms', [PageController::class, 'terms'])->name('terms');
Route::get('/delivery', [PageController::class, 'delivery'])->name('delivery');
Route::get('/returns', [PageController::class, 'returns'])->name('returns');
Route::get('/contacts', [PageController::class, 'contacts'])->name('contacts');
Route::get('/b2b', [PageController::class, 'b2b'])->name('b2b');

Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');

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
