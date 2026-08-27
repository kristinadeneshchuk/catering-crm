<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Webhook-роути від месенджерів (Viber, Instagram) перевіряють підпис самі —
        // CSRF-токен вони слати не можуть.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        // Сервісний токен для Telegram Inbox (/api/inbox/v1/*).
        $middleware->alias([
            'inbox.token' => \App\Http\Middleware\VerifyInboxToken::class,
        ]);

        // Гостей із захищених сторінок ведемо на відповідний логін, бо іменованого
        // маршруту "login" немає: клієнтів — на свій, персонал — на логін панелі.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('client*')
            ? route('client.login')
            : '/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
