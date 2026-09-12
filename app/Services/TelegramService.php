<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $token;
    private ?string $ownerChatId;
    private ?string $managerChatId;
    private ?string $cookChatId;
    private ?string $kitchenChatId;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token', '');
        $this->ownerChatId = config('services.telegram.owner_chat_id');
        $this->managerChatId = config('services.telegram.manager_chat_id');
        $this->cookChatId = config('services.telegram.cook_chat_id');
        $this->kitchenChatId = config('services.telegram.kitchen_chat_id');
    }

    public function sendToOwner(string $text): void
    {
        if ($this->ownerChatId) {
            $this->send($this->ownerChatId, $text);
        }
    }

    public function sendToManager(string $text): void
    {
        if ($this->managerChatId) {
            foreach (explode(',', $this->managerChatId) as $chatId) {
                $chatId = trim($chatId);
                if ($chatId) $this->send($chatId, $text);
            }
        }
    }

    public function sendToCook(string $text): void
    {
        if ($this->cookChatId) {
            foreach (explode(',', $this->cookChatId) as $chatId) {
                $chatId = trim($chatId);
                if ($chatId) $this->send($chatId, $text);
            }
        }
    }

    public function sendToKitchen(string $text): void
    {
        if ($this->kitchenChatId) {
            $this->send($this->kitchenChatId, $text);
        }
    }

    public function sendToOwnerAndManager(string $text): void
    {
        $this->sendToOwner($text);

        if ($this->managerChatId && $this->managerChatId !== $this->ownerChatId) {
            $this->sendToManager($text);
        }
    }

    public function sendToOwnerManagerCook(string $text): void
    {
        $this->sendToOwnerAndManager($text);

        if ($this->cookChatId
            && $this->cookChatId !== $this->ownerChatId
            && $this->cookChatId !== $this->managerChatId
        ) {
            $this->sendToCook($text);
        }
    }

    public function send(string $chatId, string $text): void
    {
        if (empty($this->token)) {
            Log::warning('TelegramService: TELEGRAM_BOT_TOKEN not set');
            return;
        }

        $response = Http::post("https://api.telegram.org/bot{$this->token}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);

        if (!$response->successful()) {
            Log::error('TelegramService: failed to send message', [
                'chat_id' => $chatId,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Кнопки й діалог — для погодження виплат і звітів курʼєрів
    // -------------------------------------------------------------------------

    /** Чати, чиїм кнопкам ми віримо: власник і старший менеджер. */
    public function approverChatIds(): array
    {
        return array_values(array_filter([
            (string) ($this->ownerChatId ?? ''),
            (string) ($this->managerChatId ?? ''),
        ]));
    }

    /**
     * Повідомлення з кнопками. Повертає message_id — щоб потім його оновити.
     *
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard  inline_keyboard
     */
    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): ?int
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];

        if ($keyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $response = $this->call('sendMessage', $payload);

        return $response['result']['message_id'] ?? null;
    }

    /** Оновити вже надіслане: після правки чи погодження — те саме повідомлення. */
    public function editMessage(string $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        $payload = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            // Порожня клавіатура прибирає кнопки: погоджене вдруге не натиснеш.
            'reply_markup' => ['inline_keyboard' => $keyboard ?? []],
        ];

        $this->call('editMessageText', $payload);
    }

    /** Відповідь на натискання кнопки — інакше в Telegram «годинник» крутиться вічно. */
    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
    }

    /**
     * Завантажити файл (фото одометра) у приватне сховище. Повертає шлях.
     */
    public function downloadFile(string $fileId, string $directory): ?string
    {
        $info = $this->call('getFile', ['file_id' => $fileId]);
        $path = $info['result']['file_path'] ?? null;

        if (! $path || empty($this->token)) {
            return null;
        }

        $body = Http::timeout(30)->get("https://api.telegram.org/file/bot{$this->token}/{$path}");

        if (! $body->successful()) {
            return null;
        }

        $target = rtrim($directory, '/').'/'.basename($path);
        \Illuminate\Support\Facades\Storage::disk('local')->put($target, $body->body());

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    public function call(string $method, array $payload): array
    {
        if (empty($this->token)) {
            Log::warning("TelegramService: TELEGRAM_BOT_TOKEN not set, {$method} skipped");

            return [];
        }

        $response = Http::timeout(15)->post("https://api.telegram.org/bot{$this->token}/{$method}", $payload);

        if (! $response->successful()) {
            Log::error("TelegramService: {$method} failed", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return [];
        }

        return $response->json() ?? [];
    }
}
