<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),

        // Модель для підбору персонального меню. Винесена в env, щоб міняти
        // без релізу: моделі оновлюються частіше, ніж ми деплоїмо.
        //
        // gpt-5.4 обрана за результатом на живих даних, а не за назвою:
        // 4-5 секунд і найкраще влучання в бриф. gpt-5.5 дає схожу якість,
        // але думає близько 40 секунд — для кнопки, біля якої чекає менеджер,
        // це задовго. gpt-5.4-mini удвічі швидша й дешевша, але меню виходить
        // одноманітнішим.
        'menu_model' => env('OPENAI_MENU_MODEL', 'gpt-5.4'),

        // Розпізнавання голосових з чату кухні (docs/tz-ops-agent.md §4).
        'transcribe_model'   => env('OPENAI_TRANSCRIBE_MODEL', 'whisper-1'),
        'transcribe_timeout' => (int) env('OPENAI_TRANSCRIBE_TIMEOUT', 120),
    ],

    // Операційний ШІ (docs/tz-ops-agent.md). Ключ — лише в .env сервера.
    'anthropic' => [
        'key'            => env('ANTHROPIC_API_KEY'),
        'model'          => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        'effort'         => env('ANTHROPIC_EFFORT', 'medium'),
        'max_tokens'     => (int) env('ANTHROPIC_MAX_TOKENS', 8000),
        'timeout'        => (int) env('ANTHROPIC_TIMEOUT', 120),
        // Скільки доларів на добу дозволено всім операційним задачам разом.
        'daily_cap_usd'  => (float) env('ANTHROPIC_DAILY_CAP_USD', 20),
    ],

    // Продукти, яких немає на складі (вода з крана) — нормою не списуємо. id через кому.
    'stock' => [
        'untracked_ingredients' => env('STOCK_UNTRACKED_INGREDIENTS', '26'),
    ],

    // Гроші бізнесу (банк, звірка) бачить лише супер адмін.
    'finance' => [
        'super_admin_ids' => env('FINANCE_SUPER_ADMIN_IDS', '7'),
        // «Звірка» розбирає банк із цієї дати; старше — історія, її не чіпаємо.
        'reconcile_from' => env('FINANCE_RECONCILE_FROM', '2026-10-01'),
    ],

    // Пульт ШІ-агентів: хто погоджує пропозиції. "user_id:telegram_id,…"
    'ai_agents' => [
        'approvers' => env('AI_APPROVERS', ''),
        // Межа собівартості продуктів від ціни дня в індивідуальних меню, %.
        'food_cost_max_pct' => (float) env('FOOD_COST_MAX_PCT', 30),
        // Скільки окремих страв поза циклом кухня тягне за день (усі індивідуальні разом).
        'kitchen_offcycle_max' => (int) env('KITCHEN_OFFCYCLE_MAX', 6),
        // КБЖВ дня проти цілі замовлення (MenuGuard V5): більше warn % — попередження, більше error % — не приймаємо.
        'kbzhu_warn_pct'  => (float) env('AI_KBZHU_WARN_PCT', 10),
        'kbzhu_error_pct' => (float) env('AI_KBZHU_ERROR_PCT', 20),
        // «Битий» продукт довідника (MenuGuard V4): error — не приймаємо меню, warn — лише бейдж.
        'broken_ingredient_level' => env('AI_BROKEN_INGREDIENT_LEVEL', 'error'),
        // Меню на D — до цієї години D−1 (кухня готує напередодні).
        'menu_deadline_hour' => (int) env('AI_MENU_DEADLINE_HOUR', 10),

        // Агенти на сервері (docs/tz-ai-agents-in-crm.md §2): черга, моделі, ліміти.
        'runtime' => [
            'queue'         => env('AGENTS_QUEUE', 'agents'),
            'daily_cap_usd' => (float) env('AGENTS_DAILY_CAP_USD', 20),
            'max_tokens'    => (int) env('AGENTS_MAX_TOKENS', 16000),
            'timeout'       => (int) env('AGENTS_TIMEOUT', 600),
            'agents' => [
                'menu' => [
                    'model'     => env('AGENT_MENU_MODEL', 'claude-opus-5-5'),
                    'effort'    => env('AGENT_MENU_EFFORT', 'high'),
                    'max_steps' => (int) env('AGENT_MENU_MAX_STEPS', 40),
                ],
                'chats' => [
                    'model'     => env('AGENT_CHATS_MODEL', 'claude-sonnet-5-5'),
                    'effort'    => env('AGENT_CHATS_EFFORT', 'medium'),
                    'max_steps' => (int) env('AGENT_CHATS_MAX_STEPS', 25),
                ],
            ],
            'tools' => [
                'menu' => \App\Services\Agents\Runtime\MenuTools::class,
                'chats' => \App\Services\Agents\Runtime\ChatsTools::class,
            ],
        ],

        // Вебхук подій Inbox (unified-inbox/docs/events-api.md): той самий секрет, що INBOX_EVENTS_SECRET в Inbox.
        'inbox_events_secret' => env('INBOX_EVENTS_SECRET', ''),

        // Агент «Переписки» (docs/tz-ai-agents-in-crm.md §5).
        'chats' => [
            'enabled'       => (bool) env('AGENT_CHATS_ENABLED', false),
            // Тінь: пропозиції лише для порівняння — не виконуються, без Telegram-кнопок.
            'shadow'        => (bool) env('AGENT_CHATS_SHADOW', true),
            'batch_minutes' => (int) env('AGENT_CHATS_BATCH_MINUTES', 10),
            'triage_model'  => env('AGENT_CHATS_TRIAGE_MODEL', 'claude-sonnet-5-5'),
            'triage_effort' => env('AGENT_CHATS_TRIAGE_EFFORT', 'low'),
        ],
        // Кому після погодження меню йде задача «надіслати меню клієнту».
        'menu_handoff_agent' => env('AI_MENU_HANDOFF_AGENT', 'inbox'),
        // Погоджені повідомлення клієнтам → Inbox (HMAC sha256(ts.body), секрет лише в env).
        'inbox_send_url'    => env('INBOX_CRM_SEND_URL', ''),
        'inbox_send_secret' => env('CRM_SEND_SECRET', ''),
    ],

    'monobank' => [
        'base_url' => env('MONOBANK_API_URL', 'https://api.monobank.ua'),
    ],

    'telegram' => [
        'bot_token'       => env('TELEGRAM_BOT_TOKEN'),
        'owner_chat_id'   => env('TELEGRAM_OWNER_CHAT_ID'),
        'manager_chat_id' => env('TELEGRAM_MANAGER_CHAT_ID'),
        'cook_chat_id'    => env('TELEGRAM_COOK_CHAT_ID'),
        'kitchen_chat_id' => env('TELEGRAM_KITCHEN_CHAT_ID'),
        // Група «Бухгалтерія»: накладні й квитанції. Підтверджують кнопками лише
        // ці люди (через кому; порожньо — власники). Непрофільні оплати бот
        // питає в особистих лише власників (accounting_owner_ids; порожньо — усіх).
        'accounting_chat_id'   => env('TELEGRAM_ACCOUNTING_CHAT_ID'),
        'accounting_approvers' => env('TELEGRAM_ACCOUNTING_APPROVER_IDS', ''),
        'accounting_owner_ids' => env('TELEGRAM_ACCOUNTING_OWNER_IDS', ''),

        // Вебхук бота сповіщень: звіти курʼєрів і кнопки погодження виплат.
        // Порожній секрет — вебхук не приймає нічого (503).
        'webhook_secret'  => env('TELEGRAM_BOT_WEBHOOK_SECRET', ''),
        // Імʼя бота без @ — для посилання підключення t.me/<бот>?start=<код>.
        'bot_username'    => env('TELEGRAM_BOT_USERNAME', ''),

        // Коли слати шаблон звіту зміни і нагадування (HH:MM, час сервера).
        'report_morning_at'        => env('COURIER_REPORT_MORNING_AT', '05:45'),
        'report_morning_remind_at' => env('COURIER_REPORT_MORNING_REMIND_AT', '12:30'),
        'report_evening_at'        => env('COURIER_REPORT_EVENING_AT', '17:00'),
        'report_evening_remind_at' => env('COURIER_REPORT_EVENING_REMIND_AT', '22:00'),

        // Для месенджер-інтеграції через MadelineProto (наступна фаза).
        // api_id / api_hash береш на https://my.telegram.org/apps
        'api_id'   => env('TELEGRAM_API_ID'),
        'api_hash' => env('TELEGRAM_API_HASH'),
    ],

    // Meta (Facebook + Instagram).
    // Усі ключі — з Meta App у https://developers.facebook.com/apps/
    'meta' => [
        'app_id'                => env('META_APP_ID'),
        'app_secret'            => env('META_APP_SECRET'),
        // Випадковий рядок 32+ символів — ставимо в Meta App → Webhooks → Verify Token
        'webhook_verify_token'  => env('META_WEBHOOK_VERIFY_TOKEN'),

        // Окремий Instagram App для нової Instagram Login API.
        // Створюється Meta автоматично при налаштуванні use case
        // «Управление сообщениями и контентом в Instagram».
        // OAuth-флоу йде через api.instagram.com з цими ключами,
        // а не через facebook.com з основними META_APP_*.
        'instagram' => [
            'app_id'     => env('INSTAGRAM_APP_ID'),
            'app_secret' => env('INSTAGRAM_APP_SECRET'),
        ],
    ],

    // Telegram Inbox — зовнішня система листування, яка оформлює замовлення
    // через /api/inbox/v1/*. Токен спільний для обох сторін, генерується
    // вручну: php -r "echo bin2hex(random_bytes(32));"
    'inbox' => [
        'token' => env('INBOX_API_TOKEN'),

        // Читання чатів для звітів операційного агента: той самий read-only
        // токен, що в агента продажів (unified-inbox/docs/events-api.md §4).
        'agent_base_url' => env('INBOX_AGENT_BASE_URL', 'https://inboxs.horenko-production.com'),
        'agent_token'    => env('INBOX_AGENT_TOKEN'),
        // Inbox ріже на 60/хв на токен — тримаємося нижче, спільно на всі процеси CRM.
        'agent_rpm'      => (int) env('INBOX_AGENT_RPM', 50),

        // Куди CRM стукає у зворотний бік — про оплату замовлення.
        // Порожньо = вебхуки вимкнені (нічого не ставиться в чергу).
        'webhook_url'    => env('INBOX_WEBHOOK_URL'),
        'webhook_secret' => env('INBOX_WEBHOOK_SECRET'),

        // Хто тримає Telegram-ботів: 'inbox' (зовнішня система листування)
        // чи 'crm' (модуль «Чати» тут).
        //
        // У бота може бути РІВНО ОДИН webhook. Якщо підключити бота в CRM,
        // Telegram перестане слати оновлення в Inbox — і навпаки. Тому це не
        // питання смаку, а взаємне виключення.
        //
        // За рішенням від 2026-08-13 Telegram обробляє Inbox, а CRM віддає
        // йому дані через /api/inbox/v1/*.
        'telegram_owner' => env('INBOX_TELEGRAM_OWNER', 'inbox'),
    ],

    // Lunch Hub — сервіс корпоративних обідів. Тягне каталог страв та
    // інгредієнтів через /api/lunch/*, назад шле зведення на кухню.
    // Токен окремий від Inbox: різні системи, різні права.
    // Генерується вручну: php -r "echo bin2hex(random_bytes(24));"
    //
    // Порожньо = ендпоінти віддають 503. Репозиторій деплоїться на три сервери,
    // і там, де Lunch Hub не підключений, вони мають бути закриті.
    'lunch' => [
        'token' => env('LUNCH_API_TOKEN', ''),
    ],

    // Viber Public Account / Bot API.
    // Кожен акаунт має свій auth_token, тому тут конфігу мало — тільки для дефолтного фолбеку.
    'viber' => [
        'default_auth_token' => env('VIBER_AUTH_TOKEN'),
    ],

];
