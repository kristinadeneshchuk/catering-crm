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

    /*
     * Менеджери в тижневому звіті. Робочі години й SLA — для часу відповіді;
     * ваги — для балів 0–100 (стартова формула, власник підправить).
     */
    'managers' => [
        'work_from'          => 8,    // година, з якої рахуємо SLA
        'work_to'            => 22,
        'sla_minutes'        => 15,   // перша відповідь у робочий час
        'unanswered_minutes' => 120,  // «залишив без відповіді»
        'night_answer_by'    => '09:30', // нічні повідомлення мають отримати відповідь до
        // Ваги балів: разом 100.
        'weights' => ['response' => 35, 'coverage' => 15, 'sales' => 30, 'errors' => 20],
        // Скільки балів знімає один факап (з блоку errors).
        'penalty_per_error' => 5,
        // Імʼя менеджера в Inbox → id користувача CRM (щоб зшити чати з продажами).
        'crm_user_map' => array_filter(array_map(
            fn ($pair) => array_map('trim', explode('=', $pair)),
            array_filter(explode(',', (string) env('OPS_MANAGER_MAP', ''))),
        )),
    ],

    // Чат оплат: власник і людина, яка платить. Сюди йде повідомлення після «ЗП погоджена».
    'payments_chat_id' => env('TELEGRAM_PAYMENTS_CHAT_ID'),

    // Рахунки, з яких платять ЗП курʼєрам (id через кому). Порожньо — усі рахунки.
    'payout_account_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('OPS_PAYOUT_ACCOUNT_IDS', ''))))),
];
