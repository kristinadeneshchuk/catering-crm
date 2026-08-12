<?php

namespace App\Services\Inbox;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\ClientChannel;

/**
 * Пошук, створення і прив'язка клієнта — спільне для картки в чаті і для API.
 *
 * Головне тут — не наплодити дублів. З переписки клієнт приходить без жодного
 * ID: є тільки телефон, який він написав текстом, та ім'я з месенджера. Тому
 * спершу завжди шукаємо серед наявних, і лише потім створюємо нового.
 */
class ClientLinker
{
    /**
     * Пошук за телефоном у будь-якому форматі.
     *
     * У CRM телефони записані як завгодно: +380..., 0..., з дужками і
     * пробілами. Тому порівнюємо тільки цифри і по останніх дев'яти — це
     * національний номер без коду країни.
     */
    public function findByPhone(?string $phone): ?Client
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) < 9) {
            return null;
        }

        $tail = substr($digits, -9);

        return Client::whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?",
            ['%'.$tail]
        )->first();
    }

    /**
     * Клієнт за каналом месенджера (Telegram ID тощо).
     */
    public function findByChannel(string $channel, string $externalId): ?Client
    {
        return ClientChannel::where('channel', $channel)
            ->where('external_id', $externalId)
            ->whereNotNull('client_id')
            ->first()?->client;
    }

    public function create(array $data): Client
    {
        return Client::create([
            'name'              => $data['name'],
            'phone'             => $data['phone'] ?? null,
            'telegram_username' => $data['telegram_username'] ?? null,
            'allergies'         => $data['allergies'] ?? null,
            'target_kcal'       => $data['target_kcal'] ?? null,
            'sales_source'      => $data['sales_source'] ?? 'telegram_inbox',
        ]);
    }

    /**
     * Прив'язати канал до клієнта. Якщо каналу ще нема — створити.
     */
    public function linkChannel(Client $client, string $channel, string $externalId, array $extra = []): ClientChannel
    {
        $attributes = array_filter([
            'client_id' => $client->id,
            'username'  => $extra['username'] ?? null,
            'project'   => $extra['project'] ?? null,
        ], fn ($v) => $v !== null);

        return tap(
            ClientChannel::firstOrNew(['channel' => $channel, 'external_id' => $externalId]),
            fn (ClientChannel $c) => $c->fill($attributes)->save()
        );
    }

    /**
     * Адреса клієнта. Домофон і спосіб передачі окремих полів у CRM не мають,
     * тому складаємо з них структурований коментар — його бачить курʼєр.
     */
    public function upsertAddress(Client $client, array $address): ?ClientAddress
    {
        if (empty($address['address'])) {
            return null;
        }

        $payload = array_filter([
            'address'           => $address['address'],
            'address_entrance'  => $address['entrance'] ?? null,
            'address_apartment' => $address['apartment'] ?? null,
            'address_floor'     => $address['floor'] ?? null,
            'delivery_comment'  => $this->buildDeliveryComment($address),
        ], fn ($v) => $v !== null && $v !== '');

        $existing = $client->addresses()->where('address', $address['address'])->first();

        if ($existing) {
            $existing->fill($payload)->save();

            return $existing;
        }

        return $client->addresses()->create($payload + [
            'is_default' => $client->addresses()->count() === 0,
        ]);
    }

    /**
     * «Домофон: 258 / Передача: залишити у консьєржа» — окремих полів під це
     * в CRM нема, тому рядками в коментарі.
     */
    public function buildDeliveryComment(array $address): ?string
    {
        $lines = [];

        if (! empty($address['intercom'])) {
            $lines[] = 'Домофон: '.$address['intercom'];
        }

        $handoff = $address['handoff'] ?? $address['delivery_comment'] ?? null;
        if (! empty($handoff)) {
            $lines[] = 'Передача: '.$handoff;
        }

        return $lines ? implode("\n", $lines) : null;
    }
}
