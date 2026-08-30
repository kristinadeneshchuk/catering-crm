<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запам'ятовує рекламну мітку на всю сесію.
 *
 * Клієнт приходить з оголошення на сторінку категорії, ходить по сайту і
 * лише через десять хвилин лишає заявку — до того моменту `utm_source` в
 * адресі давно немає. Тому мітка знімається при першому вході і живе в
 * сесії до кінця візиту, а потім лягає в заявку: менеджер в адмінці бачить
 * не просто «передзвоніть мені», а з якої саме реклами прийшов дзвінок.
 *
 * Перезаписуємо тільки якщо в адресі є нова мітка: перше джерело візиту
 * цінніше за випадковий внутрішній перехід.
 */
class RememberCampaign
{
    /** @var list<string> */
    private const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];

    public function handle(Request $request, Closure $next): Response
    {
        $marks = array_filter($request->only(self::KEYS));

        if ($marks) {
            $request->session()->put('campaign', array_map(
                fn (string $value) => mb_substr($value, 0, 120),
                $marks
            ));
        }

        return $next($request);
    }
}
