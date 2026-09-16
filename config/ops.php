<?php

/*
 * Операційний ШІ-агент і чернетки (docs/tz-ops-agent.md).
 */
return [
    // Звіт курʼєра стає чернеткою і чекає підтвердження адміном. false —
    // старий шлях: звіт одразу пише пробіг і рухає баланс.
    'courier_reports_require_review' => (bool) env('OPS_COURIER_REVIEW', true),

    // Межі позначок у звіті курʼєра.
    'anomaly' => [
        'km_over_plan_ratio'  => 1.5,   // жовта: км > план × 1,5
        'km_over_plan_red'    => 2.0,   // червона: км > план × 2
        'km_over_history'     => 1.5,   // без плану: > медіана курʼєра на цю кількість точок × 1,5
        'start_gap_km'        => 5,     // старт відрізняється від попереднього фінішу
        'fuel_price_deviation'=> 0.10,  // ціна пального ±10% від медіани за 7 днів
    ],

    // Поріг власника: фонд оплати кухні на день не більший за 130 ₴ на порцію.
    'kitchen_fot_per_portion' => (float) env('OPS_KITCHEN_FOT_PER_PORTION', 130),

    // Чат оплат: власник і людина, яка платить. Сюди йде повідомлення після «ЗП погоджена».
    'payments_chat_id' => env('TELEGRAM_PAYMENTS_CHAT_ID'),

    // Рахунки, з яких платять ЗП курʼєрам (id через кому). Порожньо — усі рахунки.
    'payout_account_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('OPS_PAYOUT_ACCOUNT_IDS', ''))))),
];
