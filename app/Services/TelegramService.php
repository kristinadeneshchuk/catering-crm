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
}
