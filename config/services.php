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
    ],

    'telegram' => [
        'bot_token'       => env('TELEGRAM_BOT_TOKEN'),
        'owner_chat_id'   => env('TELEGRAM_OWNER_CHAT_ID'),
        'manager_chat_id' => env('TELEGRAM_MANAGER_CHAT_ID'),
        'cook_chat_id'    => env('TELEGRAM_COOK_CHAT_ID'),
        'kitchen_chat_id' => env('TELEGRAM_KITCHEN_CHAT_ID'),

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

        // Куди CRM стукає у зворотний бік — про оплату замовлення.
        // Порожньо = вебхуки вимкнені (нічого не ставиться в чергу).
        'webhook_url'    => env('INBOX_WEBHOOK_URL'),
        'webhook_secret' => env('INBOX_WEBHOOK_SECRET'),
    ],

    // Viber Public Account / Bot API.
    // Кожен акаунт має свій auth_token, тому тут конфігу мало — тільки для дефолтного фолбеку.
    'viber' => [
        'default_auth_token' => env('VIBER_AUTH_TOKEN'),
    ],

];
