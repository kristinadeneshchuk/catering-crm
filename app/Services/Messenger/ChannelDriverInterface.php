<?php

namespace App\Services\Messenger;

use App\Models\Message;
use App\Models\MessengerAccount;
use App\Services\Messenger\Dto\InboundMessageData;

/**
 * Контракт, який реалізує кожен драйвер каналу (Viber, Instagram, Telegram).
 * Дозволяє решті системи (UI, Job диспетчер, Webhook handler) не знати специфіки API кожного каналу.
 */
interface ChannelDriverInterface
{
    /**
     * Відправити повідомлення-чернетку в канал.
     * Має повернути external_message_id, проставити status=sent/delivered.
     * При помилці — викинути виняток (Job сам поставить status=failed).
     */
    public function send(Message $message): void;

    /**
     * Налаштувати акаунт після створення/оновлення (зареєструвати webhook, перевірити токен).
     * Викликається з Filament action «Підключити».
     */
    public function connect(MessengerAccount $account): void;

    /**
     * Відписати webhook, погасити сесію — перед видаленням акаунта.
     */
    public function disconnect(MessengerAccount $account): void;

    /**
     * Нормалізувати сирий payload від каналу в InboundMessageData.
     * Може повернути null, якщо payload — не повідомлення (a.g. delivery receipt).
     */
    public function normalizeInbound(MessengerAccount $account, array $payload): ?InboundMessageData;
}
