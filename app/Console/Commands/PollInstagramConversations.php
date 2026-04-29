<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\MessengerAccount;
use App\Services\Messenger\Dto\InboundMessageData;
use App\Services\Messenger\InboundMessageHandler;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Періодичний polling Instagram-діалогів — work-around для dev mode,
 * у якому Meta не доставляє webhook 'messages' для реальних DM, а також
 * для Message Requests (перших звернень нових клієнтів) — для них webhook
 * не приходить і після публікації app.
 *
 * Логіка:
 *  - Primary inbox: підтягуємо всі діалоги, обробляємо нові повідомлення
 *  - Message Requests: підтягуємо, обробляємо, авто-приймаємо
 *  - Дедуплікація через external_message_id (in InboundMessageHandler)
 */
class PollInstagramConversations extends Command
{
    protected $signature   = 'messenger:poll-instagram';
    protected $description = 'Опитує Instagram на нові DM (workaround для dev mode + Message Requests)';

    private const GRAPH_BASE   = 'https://graph.instagram.com';
    private const API_VERSION  = 'v23.0';

    public function handle(InboundMessageHandler $handler): int
    {
        $accounts = MessengerAccount::query()
            ->where('channel', MessengerAccount::CHANNEL_INSTAGRAM)
            ->where('status', MessengerAccount::STATUS_ACTIVE)
            ->get();

        if ($accounts->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            try {
                $this->pollAccount($account, $handler);
            } catch (\Throwable $e) {
                Log::error('IG polling failed', [
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    protected function pollAccount(MessengerAccount $account, InboundMessageHandler $handler): void
    {
        $token = $account->credentials['access_token'] ?? null;
        if (! $token) {
            return;
        }

        $this->processFolder($account, $token, $handler, tags: null,                autoAccept: false);
        $this->processFolder($account, $token, $handler, tags: 'MESSAGE_REQUESTS', autoAccept: true);
    }

    protected function processFolder(
        MessengerAccount $account,
        string $token,
        InboundMessageHandler $handler,
        ?string $tags,
        bool $autoAccept,
    ): void {
        $params = [
            'platform' => 'instagram',
            'fields'   => 'id,updated_time,messages.limit(20){id,from,to,message,created_time,attachments}',
            'limit'    => 50,
        ];
        if ($tags) {
            $params['tags'] = $tags;
        }

        $res = Http::withToken($token)
            ->timeout(20)
            ->get(self::GRAPH_BASE . '/' . self::API_VERSION . '/me/conversations', $params);

        if (! $res->successful()) {
            Log::warning('IG polling: conversations fetch failed', [
                'account_id' => $account->id,
                'tags'       => $tags,
                'body'       => $res->body(),
            ]);
            return;
        }

        $conversations = $res->json('data') ?? [];

        foreach ($conversations as $conv) {
            $convId   = $conv['id'] ?? null;
            $messages = $conv['messages']['data'] ?? [];

            if (! $convId || ! $messages) {
                continue;
            }

            // Old → new
            foreach (array_reverse($messages) as $msg) {
                $this->processMessage($account, $token, $msg, $handler);
            }

            if ($autoAccept) {
                $this->acceptConversation($token, $convId);
            }
        }
    }

    protected function processMessage(
        MessengerAccount $account,
        string $token,
        array $msg,
        InboundMessageHandler $handler,
    ): void {
        $msgId  = $msg['id']   ?? null;
        $fromId = $msg['from']['id'] ?? null;

        if (! $msgId || ! $fromId) {
            return;
        }

        // Наші власні outbound повідомлення пропускаємо
        if ((string) $fromId === (string) $account->external_account_id) {
            return;
        }

        // Дедуплікація — InboundMessageHandler теж робить це, але тут економимо запит за профілем
        if (Message::where('external_message_id', $msgId)->exists()) {
            return;
        }

        // Контент короткого повідомлення вже у nested fields. Якщо message порожнє,
        // але є attachments — досить. Інакше — пропускаємо системні події.
        $text        = $msg['message']     ?? null;
        $attachments = $msg['attachments']['data'] ?? [];

        if (! $text && ! $attachments) {
            return;
        }

        [$type, $normalizedAttachments] = $this->normalizeAttachments($attachments);

        $sentAt = isset($msg['created_time'])
            ? Carbon::parse($msg['created_time'])
            : null;

        $profile = $this->fetchProfile($token, (string) $fromId);

        $inbound = new InboundMessageData(
            channel:           'instagram',
            externalChatId:    (string) $fromId,
            externalMessageId: (string) $msgId,
            senderExternalId:  (string) $fromId,
            senderUsername:    $profile['username'] ?? ($msg['from']['username'] ?? null),
            senderDisplayName: $profile['name']     ?? null,
            senderAvatarUrl:   $profile['profile_pic'] ?? null,
            senderPhone:       null,
            type:              $text ? Message::TYPE_TEXT : $type,
            text:              $text,
            attachments:       $normalizedAttachments,
            replyToExternalId: null,
            rawPayload:        $msg,
            sentAt:            $sentAt,
        );

        $handler->handle($account, $inbound);
    }

    protected function normalizeAttachments(array $raw): array
    {
        $list = [];
        $type = Message::TYPE_DOCUMENT;

        foreach ($raw as $att) {
            $attType = $att['type']         ?? 'file';
            $url     = $att['payload']['url'] ?? ($att['url'] ?? null);

            if (! $url) {
                continue;
            }

            $list[] = ['url' => $url, 'mime' => null, 'name' => null];

            $type = match ($attType) {
                'image'    => Message::TYPE_IMAGE,
                'video'    => Message::TYPE_VIDEO,
                'audio'    => Message::TYPE_AUDIO,
                'file'     => Message::TYPE_DOCUMENT,
                default    => Message::TYPE_DOCUMENT,
            };
        }

        return [$type, $list];
    }

    protected function fetchProfile(string $token, string $igsid): array
    {
        try {
            $res = Http::withToken($token)
                ->timeout(8)
                ->get(self::GRAPH_BASE . '/' . self::API_VERSION . '/' . $igsid, [
                    'fields' => 'name,username,profile_pic',
                ]);

            if ($res->successful()) {
                return (array) $res->json();
            }
        } catch (\Throwable) {
        }

        return [];
    }

    protected function acceptConversation(string $token, string $convId): void
    {
        try {
            Http::withToken($token)
                ->asForm()
                ->timeout(10)
                ->post(self::GRAPH_BASE . '/' . self::API_VERSION . '/' . $convId, [
                    'accepted' => 'true',
                ]);
        } catch (\Throwable $e) {
            Log::warning('IG polling: accept conversation failed', [
                'conv_id' => $convId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
