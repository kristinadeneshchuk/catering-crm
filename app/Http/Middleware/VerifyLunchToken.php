<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сервісний токен для Lunch Hub — сервісу корпоративних обідів.
 *
 * Той самий патерн, що й у Inbox: інтеграція «сервер-сервер», без користувачів
 * і сесій, тому достатньо спільного секрету в заголовку:
 *
 *   Authorization: Bearer <LUNCH_API_TOKEN>
 *
 * Токен окремий від Inbox навмисно: це різні системи з різними правами. Lunch
 * Hub тільки читає каталог і надсилає зведення, замовлень і клієнтів не бачить.
 *
 * Порожній токен дає 503, а не 401: репозиторій деплоїться на три сервери, і на
 * тих, де Lunch Hub не підключений, ендпоінти мають бути мовчазно закриті, а не
 * вдавати, що чекають правильний ключ.
 */
class VerifyLunchToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.lunch.token');

        if ($expected === '') {
            return response()->json([
                'message' => 'Lunch API не налаштований: не задано LUNCH_API_TOKEN.',
            ], 503);
        }

        $provided = (string) $request->bearerToken();

        // hash_equals — щоб не давати підказку по часу відповіді.
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Невірний сервісний токен.'], 401);
        }

        return $next($request);
    }
}
