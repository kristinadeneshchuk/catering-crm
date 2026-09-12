<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Реєструє вебхук бота сповіщень у Telegram.
 *
 * Разовий крок після деплою. Без нього бот уміє лише надсилати: ні звіти
 * курʼєрів, ні кнопки погодження до CRM не дійдуть.
 */
class TelegramBotWebhook extends Command
{
    protected $signature = 'telegram:bot-webhook {--delete : зняти вебхук} {--info : показати поточний стан}';

    protected $description = 'Підключити вебхук бота сповіщень (звіти курʼєрів, погодження виплат)';

    public function handle(TelegramService $telegram): int
    {
        if ($this->option('info')) {
            $this->line(json_encode($telegram->call('getWebhookInfo', [])['result'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('delete')) {
            $telegram->call('deleteWebhook', []);
            $this->info('Вебхук знято.');

            return self::SUCCESS;
        }

        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '') {
            $this->error('Спершу задайте TELEGRAM_BOT_WEBHOOK_SECRET у .env — без нього вебхук відповідатиме 503.');

            return self::FAILURE;
        }

        $url = route('webhooks.telegram-bot');

        $result = $telegram->call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        if (! ($result['ok'] ?? false)) {
            $this->error('Telegram відмовив: '.($result['description'] ?? 'невідома помилка'));

            return self::FAILURE;
        }

        $this->info("Вебхук підключено: {$url}");

        return self::SUCCESS;
    }
}
