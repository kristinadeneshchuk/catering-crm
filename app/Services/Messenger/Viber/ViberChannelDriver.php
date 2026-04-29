<?php

namespace App\Services\Messenger\Viber;

use App\Models\Message;
use App\Models\MessengerAccount;
use App\Services\Messenger\ChannelDriverInterface;
use App\Services\Messenger\Dto\InboundMessageData;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Драйвер для Viber Public Account / Bot API.
 *
 * Документація:
 *  - https://developers.viber.com/docs/api/rest-bot-api/
 *  - https://developers.viber.com/docs/all/  (формати webhook'ів)
 *
 * Підключення:
 *  1. Адмін отримує Auth Token у partners.viber.com
 *  2. Вводить його в CRM → MessengerAccount.credentials.auth_token
 *  3. Натискає «Підключити» → виклик connect() → реєструємо webhook у Viber
 *  4. Viber починає слати POST на /webhooks/viber/{messengerAccount}
 */
class ViberChannelDriver implements ChannelDriverInterface
{
    private const API_BASE = 'https://chatapi.viber.com/pa';

    /** Усі події Viber, які нас цікавлять */
    private const SUBSCRIBED_EVENTS = [
        'message',
        'subscribed',
        'unsubscribed',
        'conversation_started',
        'delivered',
        'seen',
        'failed',
    ];

    public function send(Message $message): void
    {
        $account      = $message->conversation->messengerAccount;
        $conversation = $message->conversation;
        $token        = $account->credentials['auth_token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Viber: auth_token не заданий для акаунта');
        }

        $payload = [
            'receiver' => $conversation->external_chat_id,
            'min_api_version' => 7,
            'sender' => [
                'name' => $account->display_name,
            ],
            'tracking_data' => (string) $message->id,
        ];

        // Базова підтримка — текст і зображення. Решту медіа додамо пізніше.
        if ($message->type === Message::TYPE_TEXT) {
            $payload['type'] = 'text';
            $payload['text'] = (string) $message->text;
        } elseif ($message->type === Message::TYPE_IMAGE) {
            $first = $message->attachments->first();
            $payload['type']  = 'picture';
            $payload['text']  = $message->text ?? '';
            $payload['media'] = $first?->file_url ?? $first?->url ?? '';
        } else {
            // Поки fallback — як текст
            $payload['type'] = 'text';
            $payload['text'] = $message->text ?? '';
        }

        $response = Http::withHeaders(['X-Viber-Auth-Token' => $token])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(self::API_BASE . '/send_message', $payload);

        $body = $response->json();

        if (! $response->successful() || ($body['status'] ?? null) !== 0) {
            $err = $body['status_message'] ?? $response->body();
            throw new RuntimeException("Viber API: {$err}");
        }

        $message->update([
            'status'              => Message::STATUS_SENT,
            'external_message_id' => isset($body['message_token']) ? (string) $body['message_token'] : null,
            'sent_at'             => now(),
        ]);
    }

    public function connect(MessengerAccount $account): void
    {
        $token = $account->credentials['auth_token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Введіть Auth Token у credentials');
        }

        // 1) Перевірка токена + отримання інформації про PA
        $info = Http::withHeaders(['X-Viber-Auth-Token' => $token])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(self::API_BASE . '/get_account_info', []);

        $infoBody = $info->json();
        if (! $info->successful() || ($infoBody['status'] ?? null) !== 0) {
            throw new RuntimeException('Viber: невалідний токен — ' . ($infoBody['status_message'] ?? $info->body()));
        }

        $accountId = $infoBody['id'] ?? null;
        $displayName = $infoBody['name'] ?? $account->display_name;

        // 2) Реєстрація webhook
        $webhookUrl = url("/webhooks/viber/{$account->id}");

        $hook = Http::withHeaders(['X-Viber-Auth-Token' => $token])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(self::API_BASE . '/set_webhook', [
                'url'                  => $webhookUrl,
                'event_types'          => self::SUBSCRIBED_EVENTS,
                'send_name'            => true,
                'send_photo'           => true,
            ]);

        $hookBody = $hook->json();
        if (! $hook->successful() || ($hookBody['status'] ?? null) !== 0) {
            throw new RuntimeException('Viber set_webhook: ' . ($hookBody['status_message'] ?? $hook->body()));
        }

        $account->update([
            'external_account_id' => $accountId,
            'display_name'        => $account->display_name ?: $displayName,
            'status'              => MessengerAccount::STATUS_ACTIVE,
            'last_error'          => null,
            'last_synced_at'      => now(),
        ]);
    }

    public function disconnect(MessengerAccount $account): void
    {
        $token = $account->credentials['auth_token'] ?? null;
        if (! $token) {
            return;
        }

        // Передаємо порожній url — це знімає webhook
        Http::withHeaders(['X-Viber-Auth-Token' => $token])
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post(self::API_BASE . '/set_webhook', ['url' => '']);
    }

    public function normalizeInbound(MessengerAccount $account, array $payload): ?InboundMessageData
    {
        $event = $payload['event'] ?? null;

        // Нас цікавить тільки 'message' (вхідне повідомлення).
        // 'conversation_started' оброблюється окремо у контролері (просто логуємо/створюємо ClientChannel).
        // 'delivered', 'seen' — оновлюють статус нашого outbound (теж окремо).
        if ($event !== 'message') {
            return null;
        }

        $sender = $payload['sender'] ?? [];
        $msg    = $payload['message'] ?? [];

        $type = $this->mapType($msg['type'] ?? 'text');
        $attachments = [];

        if (isset($msg['media'])) {
            $attachments[] = [
                'url'      => $msg['media'],
                'name'     => $msg['file_name'] ?? null,
                'size'     => $msg['size'] ?? null,
                'duration' => $msg['duration'] ?? null,
            ];
        }

        $sentAt = isset($payload['timestamp'])
            ? Carbon::createFromTimestampMs((int) $payload['timestamp'])
            : null;

        return new InboundMessageData(
            channel:           'viber',
            externalChatId:    (string) ($sender['id'] ?? ''),
            externalMessageId: isset($payload['message_token']) ? (string) $payload['message_token'] : null,
            senderExternalId:  (string) ($sender['id'] ?? ''),
            senderUsername:    null, // Viber username не дає
            senderDisplayName: $sender['name'] ?? null,
            senderAvatarUrl:   $sender['avatar'] ?? null,
            senderPhone:       null, // Viber не віддає телефон, тільки внутрішній id
            type:              $type,
            text:              $msg['text'] ?? null,
            attachments:       $attachments,
            replyToExternalId: null, // Viber не підтримує reply у простому форматі
            rawPayload:        $payload,
            sentAt:            $sentAt,
        );
    }

    private function mapType(string $viberType): string
    {
        return match ($viberType) {
            'text'         => Message::TYPE_TEXT,
            'picture'      => Message::TYPE_IMAGE,
            'video'        => Message::TYPE_VIDEO,
            'file'         => Message::TYPE_DOCUMENT,
            'sticker'      => Message::TYPE_STICKER,
            'location'     => Message::TYPE_LOCATION,
            'contact'      => Message::TYPE_TEXT,
            'url'          => Message::TYPE_TEXT,
            default        => Message::TYPE_TEXT,
        };
    }

    /**
     * Оновлення статусу outbound-повідомлення.
     * Викликається з контролера для подій delivered/seen/failed.
     */
    public function applyDeliveryUpdate(MessengerAccount $account, array $payload): void
    {
        $event       = $payload['event'] ?? null;
        $messageToken = isset($payload['message_token']) ? (string) $payload['message_token'] : null;

        if (! $messageToken) {
            return;
        }

        $message = Message::where('external_message_id', $messageToken)
            ->whereHas('conversation', fn ($q) => $q->where('messenger_account_id', $account->id))
            ->first();

        if (! $message) {
            return;
        }

        $message->update(match ($event) {
            'delivered' => ['status' => Message::STATUS_DELIVERED, 'delivered_at' => now()],
            'seen'      => ['status' => Message::STATUS_READ,      'read_at' => now()],
            'failed'    => ['status' => Message::STATUS_FAILED,    'error_message' => $payload['desc'] ?? 'Viber failed'],
            default     => [],
        });
    }
}
