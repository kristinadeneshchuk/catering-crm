<?php

namespace App\Services\Messenger;

use App\Models\Client;
use App\Models\ClientChannel;
use App\Services\Messenger\Dto\InboundMessageData;

/**
 * Знаходить або створює ClientChannel за вхідним повідомленням.
 * Спочатку шукає існуючий ClientChannel за (channel, external_id).
 * Якщо нема — пробує зматчити з існуючим Client за телефоном/username.
 * Якщо клієнта не знайшли — створює ClientChannel без client_id (потім адмін зматчить вручну).
 */
class ContactMatcher
{
    public function matchOrCreate(InboundMessageData $inbound): ClientChannel
    {
        $existing = ClientChannel::where('channel', $inbound->channel)
            ->where('external_id', $inbound->senderExternalId)
            ->first();

        if ($existing) {
            $this->refreshChannelMeta($existing, $inbound);
            return $existing;
        }

        $clientId = $this->tryMatchExistingClient($inbound)?->id;

        return ClientChannel::create([
            'client_id'    => $clientId,
            'channel'      => $inbound->channel,
            'external_id'  => $inbound->senderExternalId,
            'username'     => $inbound->senderUsername,
            'display_name' => $inbound->senderDisplayName,
            'avatar_url'   => $inbound->senderAvatarUrl,
            'raw_meta'     => $inbound->senderPhone ? ['phone' => $inbound->senderPhone] : null,
        ]);
    }

    /**
     * Якщо аватарка / імʼя змінились — оновлюємо.
     */
    protected function refreshChannelMeta(ClientChannel $channel, InboundMessageData $inbound): void
    {
        $changed = [];

        if ($inbound->senderUsername && $channel->username !== $inbound->senderUsername) {
            $changed['username'] = $inbound->senderUsername;
        }
        if ($inbound->senderDisplayName && $channel->display_name !== $inbound->senderDisplayName) {
            $changed['display_name'] = $inbound->senderDisplayName;
        }
        if ($inbound->senderAvatarUrl && $channel->avatar_url !== $inbound->senderAvatarUrl) {
            $changed['avatar_url'] = $inbound->senderAvatarUrl;
        }

        if ($changed) {
            $channel->update($changed);
        }
    }

    /**
     * Пробуємо зматчити з існуючим клієнтом CRM.
     * Логіка:
     *  - Telegram: за telegram_username (якщо у клієнта вже введений)
     *  - Instagram: за instagram_url (нормалізованим до handle)
     *  - Viber: за phone (якщо приходить)
     *  - Будь-який канал: за phone (якщо приходить)
     */
    protected function tryMatchExistingClient(InboundMessageData $inbound): ?Client
    {
        if ($inbound->senderPhone) {
            $normalizedPhone = $this->normalizePhone($inbound->senderPhone);
            $client = Client::whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', '') LIKE ?", ['%' . $normalizedPhone . '%'])
                ->first();
            if ($client) return $client;
        }

        if ($inbound->channel === 'telegram' && $inbound->senderUsername) {
            $client = Client::where('telegram_username', $inbound->senderUsername)
                ->orWhere('telegram_username', '@' . $inbound->senderUsername)
                ->first();
            if ($client) return $client;
        }

        if ($inbound->channel === 'instagram' && $inbound->senderUsername) {
            $client = Client::where('instagram_url', 'like', '%' . $inbound->senderUsername . '%')
                ->first();
            if ($client) return $client;
        }

        return null;
    }

    protected function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
