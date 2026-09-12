<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Couriers\CourierPayoutService;
use App\Services\Couriers\CourierReportService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Вебхук бота сповіщень (TELEGRAM_BOT_TOKEN) — окремо від TelegramWebhookController,
 * який обслуговує Business-акаунти Inbox.
 *
 * Сюди приходить три види подій:
 *   /start <код>      — курʼєр підключається до бота;
 *   текст або фото    — курʼєр здає звіт зміни;
 *   callback_query    — власник чи старший менеджер натиснули ✅ / ❌ під виплатою.
 *
 * Telegram чекає 200 на кожен апдейт, інакше повторює його. Тому помилки
 * обробки логуємо, а відповідаємо все одно 200.
 */
class TelegramBotWebhookController extends Controller
{
    public function __construct(
        private TelegramService $telegram,
        private CourierReportService $reports,
        private CourierPayoutService $payouts,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');

        // Без секрету на нашому боці вебхук не приймаємо взагалі: інакше будь-хто,
        // хто вгадав адресу, міг би «натиснути» погодження виплати.
        if ($secret === '') {
            return response()->json(['ok' => false], 503);
        }

        if (! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false], 401);
        }

        try {
            if ($callback = $request->input('callback_query')) {
                $this->onButton($callback);
            } elseif ($message = $request->input('message')) {
                $this->onMessage($message);
            }
        } catch (\Throwable $e) {
            Log::error('[TelegramBot] update failed', ['error' => $e->getMessage(), 'update_id' => $request->input('update_id')]);
            report($e);
        }

        return response()->json(['ok' => true]);
    }

    private function onButton(array $callback): void
    {
        $fromId = (string) ($callback['from']['id'] ?? '');
        $data   = (string) ($callback['data'] ?? '');

        if (! preg_match('/^payout:(approve|reject):(\d+)$/', $data, $m)) {
            $this->telegram->answerCallback((string) $callback['id'], 'Невідома дія.');

            return;
        }

        $answer = $this->payouts->handleButton($fromId, $m[1], (int) $m[2]);

        $this->telegram->answerCallback((string) $callback['id'], $answer);
    }

    private function onMessage(array $message): void
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text   = $message['text'] ?? $message['caption'] ?? null;

        if ($chatId === '') {
            return;
        }

        // Підключення: курʼєр відкрив посилання з кодом від менеджера.
        if ($text !== null && preg_match('/^\/start(?:\s+(\S+))?/u', trim($text), $m)) {
            $this->link($chatId, $m[1] ?? null);

            return;
        }

        $employee = Employee::where('telegram_chat_id', $chatId)->first();

        if (! $employee) {
            $this->telegram->sendMessage($chatId, 'Щоб здавати звіти, підключіться за посиланням, яке дасть менеджер.');

            return;
        }

        // Telegram шле кілька розмірів одного фото — беремо найбільший.
        $photos = [];
        if (! empty($message['photo'])) {
            $photos[] = (string) end($message['photo'])['file_id'];
        }
        if (! empty($message['document']['file_id']) && str_starts_with((string) ($message['document']['mime_type'] ?? ''), 'image/')) {
            $photos[] = (string) $message['document']['file_id'];
        }

        $reply = $this->reports->ingest($employee, $text, $photos);

        // Альбом приходить окремими повідомленнями — на кожне фото «допишіть
        // фото (1 з 2)» лише дратувало б. Мовчимо, поки бракує тільки фото.
        if (! empty($message['media_group_id']) && str_contains($reply, 'фото одометра') && ! str_contains($reply, 'пробіг')) {
            return;
        }

        $this->telegram->sendMessage($chatId, $reply);
    }

    private function link(string $chatId, ?string $code): void
    {
        $employee = $code ? Employee::where('telegram_link_code', $code)->first() : null;

        if (! $employee) {
            $already = Employee::where('telegram_chat_id', $chatId)->first();

            $this->telegram->sendMessage($chatId, $already
                ? "Ви вже підключені, {$already->name}. Шаблон звіту прийде на початку зміни."
                : 'Посилання недійсне або вже використане. Попросіть у менеджера нове.');

            return;
        }

        // Один чат — один співробітник: відвʼязуємо, якщо цей чат був у когось іншого.
        Employee::where('telegram_chat_id', $chatId)->where('id', '!=', $employee->id)->update(['telegram_chat_id' => null]);

        $employee->update(['telegram_chat_id' => $chatId, 'telegram_link_code' => null]);

        $this->telegram->sendMessage($chatId, "Готово, {$employee->name}! Шаблон звіту зміни прийде сюди на початку зміни — допишете порожнє і надішлете разом із двома фото одометра.");
    }
}
