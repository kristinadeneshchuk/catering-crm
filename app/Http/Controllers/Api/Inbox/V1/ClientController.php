<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\ClientChannel;
use App\Models\Order;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    /**
     * Пошук за телефоном або Telegram ID. Порядок як у картці менеджера:
     * спершу канал (він точний), потім телефон.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'            => ['nullable', 'string'],
            'telegram_user_id' => ['nullable', 'string'],
        ]);

        if (empty($data['phone']) && empty($data['telegram_user_id'])) {
            throw ValidationException::withMessages([
                'phone' => 'Потрібен phone або telegram_user_id.',
            ]);
        }

        $client = null;

        if (! empty($data['telegram_user_id'])) {
            $client = $this->clientByChannel('telegram', $data['telegram_user_id']);
        }

        if (! $client && ! empty($data['phone'])) {
            $client = $this->clientByPhone($data['phone']);
        }

        return $this->clientResponse($client);
    }

    /**
     * Пошук за зовнішнім ID месенджера. project_id приймаємо, але не фільтруємо
     * по ньому: на client_channels висить UNIQUE (channel, external_id), тобто
     * один Telegram-ID — рівно один запис незалежно від бренду.
     */
    public function byChannel(string $channel, string $externalId): JsonResponse
    {
        return $this->clientResponse($this->clientByChannel($channel, $externalId));
    }

    /**
     * Створення або оновлення. Ключ пошуку той самий, що і в search: спершу
     * Telegram ID, потім телефон. Наявного клієнта не перезаписуємо порожнім —
     * тільки доповнюємо те, чого в CRM ще нема.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id'                => ['nullable', 'integer', 'exists:projects,id'],
            'full_name'                 => ['required', 'string', 'max:255'],
            'phone'                     => ['required', 'string', 'max:32'],
            'telegram_user_id'          => ['nullable', 'string', 'max:64'],
            'telegram_username'         => ['nullable', 'string', 'max:64'],
            'allergies'                 => ['nullable', 'string'],
            'address'                   => ['nullable', 'array'],
            'address.address'           => ['nullable', 'string'],
            'address.entrance'          => ['nullable', 'string', 'max:32'],
            'address.apartment'         => ['nullable', 'string', 'max:32'],
            'address.floor'             => ['nullable', 'string', 'max:32'],
            'address.intercom'          => ['nullable', 'string', 'max:64'],
            'address.delivery_comment'  => ['nullable', 'string'],
            'address.handoff'           => ['nullable', 'string'],
        ]);

        $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;

        $client = null;
        if (! empty($data['telegram_user_id'])) {
            $client = $this->clientByChannel('telegram', $data['telegram_user_id']);
        }
        $client ??= $this->clientByPhone($data['phone']);

        if ($client) {
            $client->fill(array_filter([
                'telegram_username' => $data['telegram_username'] ?? null,
                'allergies'         => $data['allergies'] ?? null,
            ]))->save();
        } else {
            $client = Client::create([
                'name'              => $data['full_name'],
                'phone'             => $data['phone'],
                'telegram_username' => $data['telegram_username'] ?? null,
                'allergies'         => $data['allergies'] ?? null,
                'sales_source'      => 'telegram_inbox',
            ]);
        }

        if (! empty($data['telegram_user_id'])) {
            $this->linkChannel($client, 'telegram', $data['telegram_user_id'], [
                'username' => $data['telegram_username'] ?? null,
                'project'  => $project?->slug,
            ]);
        }

        if (! empty($data['address']['address'])) {
            $this->upsertAddress($client, $data['address']);
        }

        return $this->clientResponse($client->refresh(), 201);
    }

    /**
     * Прив'язка Telegram-акаунта до вже відомого клієнта.
     */
    public function attachChannel(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'channel_type'     => ['required', 'string', 'max:32'],
            'external_user_id' => ['required', 'string', 'max:64'],
            'username'         => ['nullable', 'string', 'max:64'],
            'project_id'       => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;

        $channel = $this->linkChannel($client, $data['channel_type'], $data['external_user_id'], [
            'username' => $data['username'] ?? null,
            'project'  => $project?->slug,
        ]);

        return response()->json([
            'channel' => [
                'id'               => $channel->id,
                'client_id'        => $channel->client_id,
                'channel_type'     => $channel->channel,
                'external_user_id' => $channel->external_id,
                'username'         => $channel->username,
                'project'          => $channel->project,
            ],
        ], 201);
    }

    /**
     * Історія замовлень — щоб менеджер бачив у Telegram останній калораж,
     * улюблений тариф і чи є борг.
     */
    public function orders(Client $client): JsonResponse
    {
        $orders = $client->orders()
            ->with('tariff:id,name')
            ->orderByDesc('start_date')
            ->limit(50)
            ->get()
            ->map(fn (Order $o) => [
                'id'             => $o->id,
                'project'        => $o->project,
                'tariff'         => $o->tariff ? ['id' => $o->tariff->id, 'name' => $o->tariff->name] : null,
                'calories'       => (int) $o->calories,
                'days'           => (int) $o->duration,
                'start_date'     => optional($o->start_date)->toDateString() ?? $o->start_date,
                'end_date'       => optional($o->end_date)->toDateString() ?? $o->end_date,
                'status'         => $o->status,
                'payment_status' => $o->is_paid ? 'paid' : 'unpaid',
                'is_paid'        => (bool) $o->is_paid,
                'subtotal'       => (float) $o->total_price,
                'discount'       => (float) $o->discount_amount,
                'total'          => (float) ($o->final_price ?? $o->total_price),
            ]);

        return response()->json([
            'client_id'      => $client->id,
            'client_balance' => (float) $client->balance,
            'data'           => $orders,
        ]);
    }

    // --- внутрішнє ---------------------------------------------------------

    protected function clientByChannel(string $channel, string $externalId): ?Client
    {
        return ClientChannel::where('channel', $channel)
            ->where('external_id', $externalId)
            ->whereNotNull('client_id')
            ->first()?->client;
    }

    /**
     * Телефони в CRM записані як завгодно (+380..., 0..., з дужками і пробілами),
     * тому порівнюємо тільки цифри і по останніх дев'яти — це національний номер
     * без коду країни.
     */
    protected function clientByPhone(string $phone): ?Client
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) < 9) {
            return null;
        }

        $tail = substr($digits, -9);

        return Client::whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?",
            ['%'.$tail]
        )->first();
    }

    protected function linkChannel(Client $client, string $channel, string $externalId, array $extra = []): ClientChannel
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
    protected function upsertAddress(Client $client, array $address): ClientAddress
    {
        $comment = $this->buildDeliveryComment($address);

        $existing = $client->addresses()
            ->where('address', $address['address'])
            ->first();

        $payload = array_filter([
            'address'           => $address['address'],
            'address_entrance'  => $address['entrance'] ?? null,
            'address_apartment' => $address['apartment'] ?? null,
            'address_floor'     => $address['floor'] ?? null,
            'delivery_comment'  => $comment,
        ], fn ($v) => $v !== null && $v !== '');

        if ($existing) {
            $existing->fill($payload)->save();

            return $existing;
        }

        return $client->addresses()->create($payload + [
            'is_default' => $client->addresses()->count() === 0,
        ]);
    }

    /**
     * «Домофон: 258 / Передача: залишити у консьєржа» — рядками, як домовлено
     * з Inbox: окремих полів під це в CRM нема.
     */
    protected function buildDeliveryComment(array $address): ?string
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

    protected function clientResponse(?Client $client, int $status = 200): JsonResponse
    {
        if (! $client) {
            return response()->json(['found' => false, 'client' => null], $status);
        }

        $client->loadMissing('addresses');

        return response()->json([
            'found'  => true,
            'client' => [
                'id'                => $client->id,
                'full_name'         => $client->name,
                'phone'             => $client->phone,
                'telegram_username' => $client->telegram_username,
                'telegram_user_id'  => $client->channels()
                    ->where('channel', 'telegram')
                    ->value('external_id'),
                'allergies'         => $client->allergies,
                'exclusions'        => $this->exclusions($client),
                'target_kcal'       => $client->target_kcal ? (int) $client->target_kcal : null,
                'balance'           => (float) $client->balance,
                'addresses'         => $client->addresses->map(fn (ClientAddress $a) => [
                    'id'               => $a->id,
                    'address'          => $a->address,
                    'entrance'         => $a->address_entrance,
                    'apartment'        => $a->address_apartment,
                    'floor'            => $a->address_floor,
                    'delivery_comment' => $a->delivery_comment,
                    'is_default'       => (bool) $a->is_default,
                ])->values(),
            ],
        ], $status);
    }

    /**
     * Виключення клієнта — інгредієнти й страви, які йому не можна.
     */
    protected function exclusions(Client $client): ?string
    {
        $items = $client->ingredientExclusions()->pluck('name')
            ->merge($client->dishExclusions()->pluck('name'))
            ->filter()
            ->values();

        return $items->isNotEmpty() ? $items->implode(', ') : null;
    }
}
