<?php

namespace App\Services\Messenger;

use App\Events\InboundMessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessengerAccount;
use App\Services\Messenger\Dto\InboundMessageData;
use Illuminate\Support\Facades\DB;

/**
 * Приймає нормалізоване вхідне повідомлення і записує в БД:
 *  - знаходить/створює ClientChannel
 *  - знаходить/створює Conversation
 *  - створює Message + Attachments
 *  - оновлює лічильник непрочитаних і прев'ю
 *  - кидає broadcast подію — щоб UI оновився в реальному часі
 *
 * Дедуплікує по external_message_id, тому повторні webhook'и безпечні.
 */
class InboundMessageHandler
{
    public function __construct(
        protected ContactMatcher $matcher,
    ) {
    }

    public function handle(MessengerAccount $account, InboundMessageData $inbound): ?Message
    {
        return DB::transaction(function () use ($account, $inbound) {
            // Дедуплікація: якщо повідомлення з таким external ID вже є — повертаємо існуюче
            if ($inbound->externalMessageId) {
                $existing = Message::where('external_message_id', $inbound->externalMessageId)
                    ->whereHas('conversation', fn ($q) => $q->where('messenger_account_id', $account->id))
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $clientChannel = $this->matcher->matchOrCreate($inbound);

            $conversation = Conversation::firstOrCreate(
                [
                    'messenger_account_id' => $account->id,
                    'client_channel_id'    => $clientChannel->id,
                ],
                [
                    'channel'          => $inbound->channel,
                    'external_chat_id' => $inbound->externalChatId,
                    'status'           => Conversation::STATUS_OPEN,
                ]
            );

            // Якщо діалог був закритий — автоматично відкриваємо при новому вхідному
            if ($conversation->status === Conversation::STATUS_CLOSED) {
                $conversation->update([
                    'status'    => Conversation::STATUS_OPEN,
                    'closed_at' => null,
                ]);
            }

            $replyToId = null;
            if ($inbound->replyToExternalId) {
                $replyToId = Message::where('external_message_id', $inbound->replyToExternalId)
                    ->where('conversation_id', $conversation->id)
                    ->value('id');
            }

            $message = Message::create([
                'conversation_id'      => $conversation->id,
                'direction'            => Message::DIRECTION_INBOUND,
                'sender_type'          => Message::SENDER_CLIENT,
                'type'                 => $inbound->type,
                'text'                 => $inbound->text,
                'external_message_id'  => $inbound->externalMessageId,
                'reply_to_message_id'  => $replyToId,
                'status'               => Message::STATUS_DELIVERED,
                'raw_payload'          => $inbound->rawPayload,
                'sent_at'              => $inbound->sentAt,
                'delivered_at'         => now(),
            ]);

            foreach ($inbound->attachments as $att) {
                MessageAttachment::create([
                    'message_id'       => $message->id,
                    'file_url'         => $att['url'] ?? null,
                    'file_name'        => $att['name'] ?? null,
                    'mime_type'        => $att['mime'] ?? null,
                    'size_bytes'       => $att['size'] ?? null,
                    'duration_seconds' => $att['duration'] ?? null,
                ]);
            }

            $preview = $this->buildPreview($inbound);

            $conversation->update([
                'last_message_at'      => $message->created_at,
                'last_message_preview' => $preview,
                'unread_count'         => DB::raw('unread_count + 1'),
            ]);

            broadcast(new InboundMessageReceived($conversation->id))->toOthers();

            return $message;
        });
    }

    protected function buildPreview(InboundMessageData $inbound): string
    {
        if ($inbound->text) {
            return mb_substr($inbound->text, 0, 200);
        }

        return match ($inbound->type) {
            'image'    => '📷 Зображення',
            'video'    => '🎬 Відео',
            'audio'    => '🎙 Голосове',
            'document' => '📎 Документ',
            'sticker'  => '🎨 Стікер',
            'location' => '📍 Локація',
            default    => '— Повідомлення —',
        };
    }
}
