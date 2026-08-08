<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\City;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Мега-меню і футер є на кожній сторінці — вантажимо один раз на запит.
        View::composer(['components.site-header', 'components.site-footer', 'components.bottom-nav'], function ($view) {
            $view->with([
                'menuCategories' => Category::roots()->get(),
                'allCities' => City::orderBy('position')->get(),
            ]);
        });

        /*
         | Сторінки помилок рендеряться повз web-middleware, тому місто там
         | не встановлене — а хедер і футер без нього падають. Підставляємо
         | збережене або дефолтне, щоб 404 залишалася нормальною сторінкою.
         */
        View::composer('*', function ($view) {
            if (! array_key_exists('city', $view->getData()) && ! View::shared('city')) {
                $view->with('city', City::where('slug', session('city', 'kyiv'))->first()
                    ?? City::orderBy('position')->first());
            }
        });
    }
}
