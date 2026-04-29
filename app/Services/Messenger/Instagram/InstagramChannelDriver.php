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
 * Драйвер для Instagram (Meta Graph API).
 *
 * Передумови:
 *  - IG акаунт переведений на Business
 *  - Привʼязаний до Facebook Page
 *  - У Privacy → Messages → "Allow access to messages" увімкнено
 *  - Meta App створений, App Review поданий (для production)
 *
 * Підключення відбувається через OAuth (InstagramOAuthController).
 * Цей драйвер вже припускає, що page_access_token отриманий.
 */
class InstagramChannelDriver implements ChannelDriverInterface
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v19.0';

    public function send(Message $message): void
    {
        $account = $message->conversation->messengerAccount;
        $pageToken = $account->credentials['page_access_token'] ?? null;

        if (! $pageToken) {
            throw new RuntimeException('Instagram: page_access_token не заданий');
        }

        $recipientId = $message->conversation->external_chat_id; // IGSID

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

        $response = Http::withToken($pageToken)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(self::GRAPH_BASE . '/me/messages', $payload);

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

    public function connect(MessengerAccount $account): void
    {
        // Для Instagram connect виконується OAuth-флоу (InstagramOAuthController).
        // Цей метод викликається після того, як OAuth уже зберіг credentials —
        // він підписує сторінку на webhooks для нашого app.

        $pageToken = $account->credentials['page_access_token'] ?? null;
        $pageId    = $account->credentials['page_id'] ?? null;

        if (! $pageToken || ! $pageId) {
            throw new RuntimeException('Instagram: спочатку пройди OAuth — кнопка «Авторизувати через Facebook»');
        }

        $response = Http::withToken($pageToken)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(self::GRAPH_BASE . "/{$pageId}/subscribed_apps", [
                'subscribed_fields' => 'messages,messaging_postbacks,message_reactions,message_reads',
            ]);

        $body = $response->json();

        if (! $response->successful() || empty($body['success'])) {
            $err = $body['error']['message'] ?? $response->body();
            throw new RuntimeException("Instagram subscribe_apps: {$err}");
        }

        $account->update([
            'status'         => MessengerAccount::STATUS_ACTIVE,
            'last_error'     => null,
            'last_synced_at' => now(),
        ]);
    }

    public function disconnect(MessengerAccount $account): void
    {
        $pageToken = $account->credentials['page_access_token'] ?? null;
        $pageId    = $account->credentials['page_id'] ?? null;

        if (! $pageToken || ! $pageId) {
            return;
        }

        Http::withToken($pageToken)
            ->timeout(10)
            ->delete(self::GRAPH_BASE . "/{$pageId}/subscribed_apps");
    }

    /**
     * Нормалізація одного messaging-евенту.
     * Контролер ітерується по entry[].messaging[] і викликає для кожного.
     */
    public function normalizeInbound(MessengerAccount $account, array $payload): ?InboundMessageData
    {
        // Echo власних повідомлень (коли наш менеджер відправляє з Instagram-додатка) —
        // приходить is_echo=true. Не робимо з них inbound, просто синхронізуємо outbound пізніше.
        if (! empty($payload['message']['is_echo'])) {
            return null;
        }

        // Не повідомлення (postback / read / reaction) — пропускаємо
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
                    'story_mention', 'share' => Message::TYPE_TEXT,
                    default    => Message::TYPE_DOCUMENT,
                };
            }
        }

        $sentAt = isset($payload['timestamp'])
            ? Carbon::createFromTimestampMs((int) $payload['timestamp'])
            : null;

        // Reply-to — якщо клієнт відповів на наше повідомлення
        $replyTo = $msg['reply_to']['mid'] ?? null;

        // Підвантажуємо профіль користувача, щоб мати імʼя і аватар.
        // Це ОПЦІЙНО — якщо не вийде, запишемо без імʼя, потім ContactMatcher сам спробує знайти.
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
     * GET /{ig-scoped-id}?fields=name,username,profile_pic
     * Може повернути порожнє — і це ок.
     */
    protected function fetchUserProfile(MessengerAccount $account, string $igsid): array
    {
        $token = $account->credentials['page_access_token'] ?? null;
        if (! $token) {
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(8)
                ->get(self::GRAPH_BASE . "/{$igsid}", [
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
     * Оновити long-lived page access token через user access token.
     * Викликається з RefreshInstagramTokens command.
     */
    public function refreshToken(MessengerAccount $account): void
    {
        $userToken = $account->credentials['user_access_token'] ?? null;
        $pageId    = $account->credentials['page_id'] ?? null;

        if (! $userToken || ! $pageId) {
            return;
        }

        // Long-lived user access token живе 60 днів. Кожні ~50 днів обмінюємо на новий.
        $appId     = config('services.meta.app_id');
        $appSecret = config('services.meta.app_secret');

        $exchanged = Http::timeout(15)->get(self::GRAPH_BASE . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $appId,
            'client_secret'     => $appSecret,
            'fb_exchange_token' => $userToken,
        ]);

        if (! $exchanged->successful()) {
            $account->update([
                'status'     => MessengerAccount::STATUS_EXPIRED,
                'last_error' => 'Не вдалося оновити user token: ' . $exchanged->body(),
            ]);
            return;
        }

        $newUserToken = $exchanged->json('access_token');

        // Заново витягуємо page access token
        $pages = Http::timeout(15)->get(self::GRAPH_BASE . '/me/accounts', [
            'access_token' => $newUserToken,
        ]);

        if (! $pages->successful()) {
            $account->update([
                'status'     => MessengerAccount::STATUS_EXPIRED,
                'last_error' => 'Не вдалося отримати pages: ' . $pages->body(),
            ]);
            return;
        }

        $matchedPage = collect($pages->json('data') ?? [])->firstWhere('id', $pageId);
        if (! $matchedPage) {
            $account->update([
                'status'     => MessengerAccount::STATUS_EXPIRED,
                'last_error' => "Page {$pageId} більше не доступний з цим токеном",
            ]);
            return;
        }

        $newCreds = array_merge($account->credentials ?? [], [
            'user_access_token' => $newUserToken,
            'page_access_token' => $matchedPage['access_token'],
            'token_refreshed_at' => now()->toIso8601String(),
        ]);

        $account->update([
            'credentials'    => $newCreds,
            'status'         => MessengerAccount::STATUS_ACTIVE,
            'last_error'     => null,
            'last_synced_at' => now(),
        ]);
    }
}
