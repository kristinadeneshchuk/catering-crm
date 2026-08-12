<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сервісний токен для Telegram Inbox.
 *
 * Це інтеграція «сервер-сервер», без користувачів і сесій, тому повноцінний
 * Sanctum не потрібен — достатньо спільного секрету в заголовку:
 *
 *   Authorization: Bearer <INBOX_API_TOKEN>
 *
 * Порівнюємо через hash_equals, щоб не давати підказку по часу відповіді.
 */
class VerifyInboxToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.inbox.token');

        if ($expected === '') {
            return response()->json([
                'message' => 'Inbox API не налаштований: не задано INBOX_API_TOKEN.',
            ], 503);
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Невірний сервісний токен.'], 401);
        }

        return $next($request);
    }
}
