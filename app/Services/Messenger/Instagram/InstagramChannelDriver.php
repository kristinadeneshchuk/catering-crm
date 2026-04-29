<?php

namespace App\Services\Messenger\Instagram;

use App\Models\Message;
use App\Models\MessengerAccount;
use App\Services\Messenger\ChannelDriverInterface;
use App\Services\Messenger\Dto\InboundMessageData;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Драйвер Instagram через нову Instagram Login API
 * (Instagram API with Instagram Login, https://graph.instagram.com).
 *
 * Передумови:
 *  - IG акаунт = Business
 *  - У Privacy → Messages → "Allow access to messages" увімкнено
 *  - Окремий Instagram App створений у Meta App use case
 *    «Управление сообщениями и контентом в Instagram»
 *  - OAuth уже пройдений (InstagramOAuthController), credentials.access_token у БД
 */
class InstagramChannelDriver implements ChannelDriverInterface
{
    private const GRAPH_BASE = 'https://graph.instagram.com';
    private const API_VERSION = 'v23.0';

    public function send(Message $message): void
    {
        $account  = $message->conversation->messengerAccount;
        $token    = $account->credentials['access_token'] ?? null;
        $igUserId = $account->credentials['user_id'] ?? null;

        if (! $token || ! $igUserId) {
            throw new RuntimeException('Instagram: access_token або user_id не задані. Пройди OAuth.');
        }

        $recipientId = $message->conversation->external_chat_id; // IGSID отримувача

        $payload = [
            'recipient' => ['id' => $recipientId],
        ];

        if ($message->type === Message::TYPE_TEXT) {
            $payload['message'] = ['text' => (string) $message->text];
        } elseif ($message->type === Message::TYPE_IMAGE) {
            $first = $message->attachments->first();
            $payload['message'] = [
                'attachment' => [
                    'type'    => 'image',
                    'payload' => ['url' => $first?->file_url ?? '', 'is_reusable' => false],
                ],
            ];
        } else {
            // Fallback як текст
            $payload['message'] = ['text' => $message->text ?? ''];
        }

        $url = self::GRAPH_BASE . '/' . self::API_VERSION . "/{$igUserId}/messages";

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post($url, $payload);

        $body = $response->json();

        if (! $response->successful()) {
            $err = $body['error']['message'] ?? $response->body();
            throw new RuntimeException("Instagram: {$err}");
        }

        $message->update([
            'status'              => Message::STATUS_SENT,
            'external_message_id' => $body['message_id'] ?? null,
            'sent_at'             => now(),
        ]);
    }

    /**
     * У новій Instagram Login API webhook-підписки конфігуруються на рівні App,
     * а не per-користувач. Тут просто перевіряємо, що токен валідний,
     * викликаючи /me — якщо успішно, акаунт «активний».
     */
    public function connect(MessengerAccount $account): void
    {
        $token = $account->credentials['access_token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Instagram: спочатку пройди OAuth — кнопка «Авторизувати через Instagram»');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->get(self::GRAPH_BASE . '/' . self::API_VERSION . '/me', [
                'fields' => 'user_id,username,account_type',
            ]);

        if (! $response->successful()) {
            $err = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("Instagram /me: {$err}");
        }

        $account->update([
            'status'         => MessengerAccount::STATUS_ACTIVE,
            'last_error'     => null,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Відкликати токен. Instagram має endpoint /me/permissions DELETE для цього.
     */
    public function disconnect(MessengerAccount $account): void
    {
        $token = $account->credentials['access_token'] ?? null;
        if (! $token) {
            return;
        }

        try {
            Http::withToken($token)
                ->timeout(10)
                ->delete(self::GRAPH_BASE . '/' . self::API_VERSION . '/me/permissions');
        } catch (\Throwable $e) {
            Log::warning('IG disconnect failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Нормалізація одного messaging-евенту з webhook'а.
     * Контролер ітерується по entry[].messaging[] і викликає для кожного.
     */
    public function normalizeInbound(MessengerAccount $account, array $payload): ?InboundMessageData
    {
        // Echo власних повідомлень (коли менеджер відправляє з Instagram-додатка) —
        // приходить is_echo=true. Не дублюємо як inbound.
        if (! empty($payload['message']['is_echo'])) {
            return null;
        }

        // Не повідомлення (read / reaction / postback) — пропускаємо
        if (! isset($payload['message'])) {
            return null;
        }

        $senderId = $payload['sender']['id'] ?? null;
        $msg      = $payload['message'] ?? [];

        if (! $senderId || ! $msg) {
            return null;
        }

        // Атачменти
        $attachments = [];
        $type        = Message::TYPE_TEXT;

        foreach ($msg['attachments'] ?? [] as $att) {
            $attType = $att['type'] ?? 'file';
            $url     = $att['payload']['url'] ?? null;

            if ($url) {
                $attachments[] = [
                    'url'  => $url,
                    'mime' => null,
                    'name' => null,
                ];
                $type = match ($attType) {
                    'image'    => Message::TYPE_IMAGE,
                    'video'    => Message::TYPE_VIDEO,
                    'audio'    => Message::TYPE_AUDIO,
                    'file'     => Message::TYPE_DOCUMENT,
                    'story_mention', 'share', 'ig_reel' => Message::TYPE_TEXT,
                    default    => Message::TYPE_DOCUMENT,
                };
            }
        }

        $sentAt = isset($payload['timestamp'])
            ? Carbon::createFromTimestampMs((int) $payload['timestamp'])
            : null;

        // Reply-to — якщо клієнт відповів на наше повідомлення
        $replyTo = $msg['reply_to']['mid'] ?? null;

        // Підвантажуємо профіль користувача
        $profile = $this->fetchUserProfile($account, (string) $senderId);

        return new InboundMessageData(
            channel:           'instagram',
            externalChatId:    (string) $senderId,
            externalMessageId: $msg['mid'] ?? null,
            senderExternalId:  (string) $senderId,
            senderUsername:    $profile['username'] ?? null,
            senderDisplayName: $profile['name'] ?? null,
            senderAvatarUrl:   $profile['profile_pic'] ?? null,
            senderPhone:       null,
            type:              $type,
            text:              $msg['text'] ?? null,
            attachments:       $attachments,
            replyToExternalId: $replyTo,
            rawPayload:        $payload,
            sentAt:            $sentAt,
        );
    }

    /**
     * GET /v23.0/{IGSID}?fields=name,username,profile_pic
     * У Instagram Login API цей endpoint доступний з нашим access_token.
     */
    protected function fetchUserProfile(MessengerAccount $account, string $igsid): array
    {
        $token = $account->credentials['access_token'] ?? null;
        if (! $token) {
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(8)
                ->get(self::GRAPH_BASE . '/' . self::API_VERSION . "/{$igsid}", [
                    'fields' => 'name,username,profile_pic',
                ]);

            if ($response->successful()) {
                return (array) $response->json();
            }
        } catch (\Throwable $e) {
            Log::warning('IG fetchUserProfile failed', [
                'igsid' => $igsid,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Оновити long-lived access token.
     * GET /refresh_access_token?grant_type=ig_refresh_token&access_token=...
     * Можна викликати лише коли токен ще валідний (мінімум 24 години після створення).
     * Викликається з RefreshInstagramTokens command.
     */
    public function refreshToken(MessengerAccount $account): void
    {
        $token = $account->credentials['access_token'] ?? null;
        if (! $token) {
            return;
        }

        $response = Http::timeout(15)->get(self::GRAPH_BASE . '/refresh_access_token', [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $token,
        ]);

        if (! $response->successful()) {
            $account->update([
                'status'     => MessengerAccount::STATUS_EXPIRED,
                'last_error' => 'Не вдалось оновити Instagram токен: ' . $response->body(),
            ]);
            return;
        }

        $newToken  = $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in');

        $newCreds = array_merge($account->credentials ?? [], [
            'access_token'        => $newToken,
            'token_expires_at'    => $expiresIn ? now()->addSeconds($expiresIn)->toIso8601String() : null,
            'token_refreshed_at'  => now()->toIso8601String(),
        ]);

        $account->update([
            'credentials'    => $newCreds,
            'status'         => MessengerAccount::STATUS_ACTIVE,
            'last_error'     => null,
            'last_synced_at' => now(),
        ]);
    }
}
