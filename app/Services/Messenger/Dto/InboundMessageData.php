<?php

namespace App\Services\Messenger\Dto;

/**
 * Уніфікований формат вхідного повідомлення з будь-якого каналу.
 * Драйвер канал-специфічного API нормалізує raw payload у цей DTO,
 * далі InboundMessageHandler не знає звідки повідомлення прийшло.
 */
class InboundMessageData
{
    public function __construct(
        /** telegram | instagram | viber */
        public readonly string $channel,

        /** ID треда в каналі (chat_id для TG, conversation thread для IG, user_id для Viber) */
        public readonly string $externalChatId,

        /** Зовнішній ID самого повідомлення (для дедуплікації) */
        public readonly ?string $externalMessageId,

        /** ID контакту-відправника в каналі */
        public readonly string $senderExternalId,

        /** username / handle, без @ */
        public readonly ?string $senderUsername = null,

        /** Імʼя в каналі */
        public readonly ?string $senderDisplayName = null,

        /** URL аватарки */
        public readonly ?string $senderAvatarUrl = null,

        /** Телефон, якщо канал його віддає (Viber дає, Telegram у певних кейсах) */
        public readonly ?string $senderPhone = null,

        /** text | image | video | audio | document | sticker | location | system */
        public readonly string $type = 'text',

        public readonly ?string $text = null,

        /** Список вкладень: [['url' => '...', 'mime' => '...', 'name' => '...', 'size' => 123, 'duration' => 5], ...] */
        public readonly array $attachments = [],

        /** External ID повідомлення, на яке відповідаємо */
        public readonly ?string $replyToExternalId = null,

        /** Сирий payload — для дебагу і відновлення */
        public readonly array $rawPayload = [],

        /** Час відправки в каналі */
        public readonly ?\DateTimeInterface $sentAt = null,
    ) {
    }
}
