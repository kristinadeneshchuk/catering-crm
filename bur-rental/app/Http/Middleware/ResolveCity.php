<?php

namespace App\Http\Middleware;

use App\Models\City;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Обране місто — наскрізний контекст: від нього залежать телефон у хедері,
 * список філій, зони й вартість доставки. Тому воно резолвиться один раз
 * на запит і кладеться в атрибути, а не питається в кожному контролері.
 */
class ResolveCity
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('city') ?? $request->session()->get('city', 'kyiv');
        $slug = $slug instanceof City ? $slug->slug : $slug;

        $city = City::where('slug', $slug)->first() ?? City::orderBy('position')->firstOrFail();

        // Перехід на сторінку міста = вибір міста. Клієнт не має шукати селектор.
        $request->session()->put('city', $city->slug);

        $request->attributes->set('city', $city);
        View::share('city', $city);

        return $next($request);
    }
}
