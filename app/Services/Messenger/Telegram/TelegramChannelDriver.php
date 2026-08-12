<?php

namespace App\Services\Messenger\Telegram;

use App\Models\Message;
use App\Models\MessengerAccount;
use App\Services\Messenger\ChannelDriverInterface;
use App\Services\Messenger\Dto\InboundMessageData;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Драйвер для Telegram Business.
 *
 * Клієнти пишуть не боту, а на живий бізнес-акаунт бренду — так, як звикли.
 * Бот підключається до цього акаунта через Налаштування Telegram → Telegram
 * Business → Чат-боти, після чого отримує оновлення типу business_message і
 * може відповідати від імені акаунта.
 *
 * Документація:
 *  - https://core.telegram.org/bots/api#business-connection
 *  - https://core.telegram.org/bots/api#businessmessagesdeleted
 *
 * Підключення:
 *  1. Створюємо бота в @BotFather, отримуємо токен.
 *  2. Вводимо токен у CRM → MessengerAccount.credentials.bot_token.
 *  3. Тиснемо «Підключити» → connect(): перевіряємо токен і реєструємо webhook.
 *  4. Власник бізнес-акаунта додає бота в Telegram Business → Чат-боти.
 *     Telegram шле update business_connection — звідти беремо
 *     business_connection_id, без якого не можна нічого відправити.
 *
 * Один бот може обслуговувати кілька бізнес-акаунтів, але в нас на кожен бренд
 * свій MessengerAccount зі своїм connection — так бренд визначається сам собою.
 */
class TelegramChannelDriver implements ChannelDriverInterface
{
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * Тільки бізнес-оновлення. Звичайні message нам не потрібні: у боту як
     * такого ніхто не пише, він лише «руки» бізнес-акаунта.
     */
    private const ALLOWED_UPDATES = [
        'business_connection',
        'business_message',
        'edited_business_message',
        'deleted_business_messages',
    ];

    public function send(Message $message): void
    {
        $account      = $message->conversation->messengerAccount;
        $conversation = $message->conversation;

        $token        = $this->token($account);
        $connectionId = $account->credentials['business_connection_id'] ?? null;

        if (! $connectionId) {
            throw new RuntimeException(
                'Telegram: бот ще не підключений до бізнес-акаунта. '
                .'Відкрий Telegram → Налаштування → Telegram Business → Чат-боти і додай бота.'
            );
        }

        $payload = [
            'business_connection_id' => $connectionId,
            'chat_id'                => $conversation->external_chat_id,
        ];

        $first = $message->attachments->first();
        $mediaUrl = $first?->file_url ?? $first?->url ?? null;

        // Медіа шлемо URL'ом — Telegram сам його завантажить.
        [$method, $payload] = match (true) {
            $message->type === Message::TYPE_IMAGE && $mediaUrl => ['sendPhoto', $payload + [
                'photo'   => $mediaUrl,
                'caption' => $message->text ?: null,
            ]],
            $message->type === Message::TYPE_DOCUMENT && $mediaUrl => ['sendDocument', $payload + [
                'document' => $mediaUrl,
                'caption'  => $message->text ?: null,
            ]],
            default => ['sendMessage', $payload + [
                'text' => (string) $message->text,
            ]],
        };

        $response = Http::acceptJson()->asJson()->timeout(15)
            ->post(self::API_BASE.$token.'/'.$method, array_filter($payload, fn ($v) => $v !== null));

        $body = $response->json();

        if (! $response->successful() || ($body['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram API: '.($body['description'] ?? $response->body()));
        }

        $message->update([
            'status'              => Message::STATUS_SENT,
            'external_message_id' => isset($body['result']['message_id'])
                ? (string) $body['result']['message_id']
                : null,
            'sent_at'             => now(),
        ]);
    }

    public function connect(MessengerAccount $account): void
    {
        $token = $this->token($account);

        $me = Http::acceptJson()->timeout(15)->get(self::API_BASE.$token.'/getMe');
        $meBody = $me->json();

        if (! $me->successful() || ($meBody['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram: невалідний токен — '.($meBody['description'] ?? $me->body()));
        }

        // Секрет у заголовку — єдиний спосіб переконатись, що webhook смикає
        // саме Telegram, а не хтось, хто вгадав адресу.
        $secret = $account->credentials['webhook_secret'] ?? bin2hex(random_bytes(16));

        $hook = Http::acceptJson()->asJson()->timeout(15)
            ->post(self::API_BASE.$token.'/setWebhook', [
                'url'             => url("/webhooks/telegram/{$account->id}"),
                'secret_token'    => $secret,
                'allowed_updates' => self::ALLOWED_UPDATES,
                'drop_pending_updates' => true,
            ]);

        $hookBody = $hook->json();

        if (! $hook->successful() || ($hookBody['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram setWebhook: '.($hookBody['description'] ?? $hook->body()));
        }

        $account->update([
            'display_name'   => $account->display_name ?: ($meBody['result']['username'] ?? 'Telegram'),
            'credentials'    => array_merge($account->credentials ?? [], [
                'webhook_secret' => $secret,
                'bot_username'   => $meBody['result']['username'] ?? null,
            ]),
            // Ще не active: активним акаунт стає, коли власник додасть бота в
            // Telegram Business і прилетить business_connection.
            'status'         => $this->hasConnection($account)
                ? MessengerAccount::STATUS_ACTIVE
                : MessengerAccount::STATUS_INACTIVE,
            'last_error'     => $this->hasConnection($account)
                ? null
                : 'Webhook зареєстровано. Додай бота @'.($meBody['result']['username'] ?? '?')
                    .' у Telegram → Налаштування → Telegram Business → Чат-боти.',
            'last_synced_at' => now(),
        ]);
    }

    public function disconnect(MessengerAccount $account): void
    {
        $token = $account->credentials['bot_token'] ?? null;

        if (! $token) {
            return;
        }

        Http::acceptJson()->asJson()->timeout(10)
            ->post(self::API_BASE.$token.'/deleteWebhook');
    }

    /**
     * Оновлення про підключення/відключення бота до бізнес-акаунта.
     * Тут ми дізнаємось business_connection_id — без нього send() неможливий.
     */
    public function applyConnectionUpdate(MessengerAccount $account, array $connection): void
    {
        $enabled = ($connection['is_enabled'] ?? false) === true;

        $account->update([
            'external_account_id' => isset($connection['user']['id'])
                ? (string) $connection['user']['id']
                : $account->external_account_id,
            'credentials' => array_merge($account->credentials ?? [], [
                'business_connection_id' => $connection['id'] ?? null,
                'can_reply'              => $connection['rights']['can_reply']
                    ?? $connection['can_reply']
                    ?? null,
            ]),
            'status'     => $enabled ? MessengerAccount::STATUS_ACTIVE : MessengerAccount::STATUS_INACTIVE,
            'last_error' => $enabled ? null : 'Бота відключено від бізнес-акаунта.',
            'last_synced_at' => now(),
        ]);
    }

    public function normalizeInbound(MessengerAccount $account, array $payload): ?InboundMessageData
    {
        $msg = $payload['business_message'] ?? null;

        if (! $msg) {
            return null;
        }

        // Власник бізнес-акаунта пише сам зі свого телефону — це не вхідне
        // повідомлення, а наше вихідне. Обробляється окремо (recordOwnEcho).
        if ($this->isFromOwner($account, $msg)) {
            return null;
        }

        $from = $msg['from'] ?? [];
        $chat = $msg['chat'] ?? [];

        [$type, $text, $attachments] = $this->extractContent($msg);

        $displayName = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: null;

        return new InboundMessageData(
            channel:           MessengerAccount::CHANNEL_TELEGRAM,
            externalChatId:    (string) ($chat['id'] ?? $from['id'] ?? ''),
            externalMessageId: isset($msg['message_id']) ? (string) $msg['message_id'] : null,
            senderExternalId:  (string) ($from['id'] ?? ''),
            senderUsername:    $from['username'] ?? null,
            senderDisplayName: $displayName,
            senderAvatarUrl:   null, // окремий getUserProfilePhotos — поки не тягнемо
            senderPhone:       $msg['contact']['phone_number'] ?? null,
            type:              $type,
            text:              $text,
            attachments:       $attachments,
            replyToExternalId: isset($msg['reply_to_message']['message_id'])
                ? (string) $msg['reply_to_message']['message_id']
                : null,
            rawPayload:        $payload,
            sentAt:            isset($msg['date']) ? Carbon::createFromTimestamp((int) $msg['date']) : null,
        );
    }

    /**
     * Менеджер відповів прямо з телефону, не через CRM. Щоб історія в CRM не
     * була однобокою, записуємо це як наше вихідне повідомлення.
     */
    public function isFromOwner(MessengerAccount $account, array $message): bool
    {
        $ownerId = $account->external_account_id;
        $fromId  = $message['from']['id'] ?? null;

        return $ownerId && $fromId && (string) $fromId === (string) $ownerId;
    }

    /**
     * Розкладає повідомлення на тип, текст і вкладення.
     *
     * @return array{0: string, 1: ?string, 2: array<int, array<string, mixed>>}
     */
    protected function extractContent(array $msg): array
    {
        $caption = $msg['caption'] ?? null;

        if (isset($msg['photo'])) {
            // Telegram шле кілька розмірів — беремо найбільший.
            $largest = end($msg['photo']) ?: [];

            return [Message::TYPE_IMAGE, $caption, [[
                'file_id' => $largest['file_id'] ?? null,
                'size'    => $largest['file_size'] ?? null,
            ]]];
        }

        foreach ([
            'video'      => Message::TYPE_VIDEO,
            'voice'      => Message::TYPE_AUDIO,
            'audio'      => Message::TYPE_AUDIO,
            'document'   => Message::TYPE_DOCUMENT,
            'sticker'    => Message::TYPE_STICKER,
        ] as $key => $type) {
            if (isset($msg[$key])) {
                return [$type, $caption, [[
                    'file_id'  => $msg[$key]['file_id'] ?? null,
                    'name'     => $msg[$key]['file_name'] ?? null,
                    'mime'     => $msg[$key]['mime_type'] ?? null,
                    'size'     => $msg[$key]['file_size'] ?? null,
                    'duration' => $msg[$key]['duration'] ?? null,
                ]]];
            }
        }

        if (isset($msg['location'])) {
            $loc = $msg['location'];

            return [Message::TYPE_LOCATION, ($loc['latitude'] ?? '').','.($loc['longitude'] ?? ''), []];
        }

        if (isset($msg['contact'])) {
            $c = $msg['contact'];

            return [Message::TYPE_TEXT, trim(($c['first_name'] ?? '').' '.($c['phone_number'] ?? '')), []];
        }

        return [Message::TYPE_TEXT, $msg['text'] ?? $caption, []];
    }

    protected function hasConnection(MessengerAccount $account): bool
    {
        return ! empty($account->credentials['business_connection_id']);
    }

    protected function token(MessengerAccount $account): string
    {
        $token = $account->credentials['bot_token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Telegram: bot_token не заданий для акаунта');
        }

        return $token;
    }
}
