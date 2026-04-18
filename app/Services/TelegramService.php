<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $token;
    private ?string $ownerChatId;
    private ?string $managerChatId;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token', '');
        $this->ownerChatId = config('services.telegram.owner_chat_id');
        $this->managerChatId = config('services.telegram.manager_chat_id');
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
            $this->send($this->managerChatId, $text);
        }
    }

    public function sendToOwnerAndManager(string $text): void
    {
        $this->sendToOwner($text);

        if ($this->managerChatId && $this->managerChatId !== $this->ownerChatId) {
            $this->sendToManager($text);
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
}
