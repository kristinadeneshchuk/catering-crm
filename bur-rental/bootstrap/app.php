<?php

use App\Http\Middleware\ResolveCity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ResolveCity::class,
        ]);

        // Кабінет — єдине місце на сайті, куди пускають за сесією. Без цих
        // двох рядків Laravel шле гостя на неіснуючий /login, а вже
        // залогіненого — на /dashboard, якого в проєкті теж немає.
        $middleware->redirectGuestsTo(fn () => route('cabinet.login'));
        $middleware->redirectUsersTo(fn () => route('cabinet'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
