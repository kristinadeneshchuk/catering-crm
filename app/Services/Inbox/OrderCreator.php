<?php

namespace App\Services\Inbox;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\Project;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Створення замовлення з переписки — і з картки в чаті, і через Inbox API.
 *
 * Живе окремо навмисно: щойно з'явиться друга копія цієї логіки, вона почне
 * розходитись із першою. У проєкті вже є така історія зі статусами замовлення,
 * повторювати її не хочеться.
 *
 * Дні створюємо так само, як CreateOrder::afterCreate — підряд від старту або
 * за явним списком. Без днів замовлення не потрапляє ні у виробництво, ні в
 * логістику, тобто фактично не існує.
 */
class OrderCreator
{
    /** Ранкова / вечірня доставка. У CRM це schedule_type, а не окреме поле. */
    public const WINDOWS = [
        'morning' => 'every_day_morning',
        'evening' => 'every_day_evening',
    ];

    public function __construct(
        protected PricingService $pricing,
    ) {
    }

    /**
     * @param  array{
     *     client: Client,
     *     project: Project,
     *     tariff: Tariff,
     *     calories: int,
     *     dates: array<int, string>,
     *     discount?: array{type?: ?string, value?: float|int|null},
     *     delivery_window?: ?string,
     *     delivery_time?: ?string,
     *     discount_reason?: ?string,
     *     comment?: ?string,
     *     source?: ?string,
     *     address?: array<string, mixed>,
     * }  $data
     * @return array{order: Order, quote: array<string, mixed>}
     */
    public function create(array $data): array
    {
        $dates    = $data['dates'];
        $discount = $data['discount'] ?? ['type' => null, 'value' => null];

        // Рахуємо ДО створення: якщо ціни немає, замовлення не має з'явитись узагалі.
        $quote = $this->pricing->quote(
            $data['tariff'],
            $data['calories'],
            count($dates),
            $discount,
        );

        $order = DB::transaction(function () use ($data, $dates, $discount) {
            $order = Order::create([
                'client_id'       => $data['client']->id,
                'project'         => $data['project']->slug,
                'tariff_id'       => $data['tariff']->id,
                'calories'        => $data['calories'],
                'duration'        => count($dates),
                'start_date'      => $dates[0],
                'end_date'        => end($dates),
                'scale_factor'    => 1.0,
                'schedule_type'   => self::WINDOWS[$data['delivery_window'] ?? 'morning'] ?? self::WINDOWS['morning'],
                'delivery_time'   => $data['delivery_time'] ?? null,
                'discount_type'   => $discount['type'] ?? null,
                'discount_value'  => $discount['value'] ?? null,
                'discount_reason' => $data['discount_reason'] ?? null,
                'source'          => $data['source'] ?? null,
                'comment'         => $data['comment'] ?? null,
            ]);

            $dayAddress = $this->dayAddress($data['address'] ?? []);

            foreach ($dates as $date) {
                OrderDay::firstOrCreate(
                    ['order_id' => $order->id, 'date' => $date],
                    $dayAddress,
                );
            }

            $order->refresh()->recomputeStatus();

            return $order->refresh();
        });

        return ['order' => $order, 'quote' => $quote];
    }

    /**
     * Дати доставки: явний список («рвані» дні) або підряд від start_date.
     *
     * @return array<int, string>
     */
    public function resolveDates(string $startDate, int $days, ?array $explicit = null): array
    {
        if (! empty($explicit)) {
            $dates = collect($explicit)
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($dates === []) {
                throw ValidationException::withMessages([
                    'delivery_days' => 'Список днів доставки порожній.',
                ]);
            }

            return $dates;
        }

        $start = Carbon::parse($startDate);

        return collect(range(0, max(1, $days) - 1))
            ->map(fn (int $i) => $start->copy()->addDays($i)->toDateString())
            ->all();
    }

    /**
     * Тариф має належати саме цьому бренду — інакше замовлення A Food
     * порахується за цінами u-fit.
     */
    public function tariffForProject(int $tariffId, Project $project): Tariff
    {
        $tariff = Tariff::where('id', $tariffId)->where('is_active', true)->first();

        if (! $tariff) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Тариф не знайдено або він неактивний.',
            ]);
        }

        if ($tariff->project !== $project->slug) {
            throw ValidationException::withMessages([
                'tariff_id' => "Тариф «{$tariff->name}» не належить бренду «{$project->name}».",
            ]);
        }

        return $tariff;
    }

    /**
     * Адреса на кожен день замовлення, якщо її передали. Домофон і спосіб
     * передачі окремих полів у CRM не мають — складаємо структурований коментар.
     */
    public function dayAddress(array $address): array
    {
        if (empty($address['address'])) {
            return [];
        }

        $lines = [];

        if (! empty($address['intercom'])) {
            $lines[] = 'Домофон: '.$address['intercom'];
        }

        $handoff = $address['handoff'] ?? $address['delivery_comment'] ?? null;
        if (! empty($handoff)) {
            $lines[] = 'Передача: '.$handoff;
        }

        return array_filter([
            'address'           => $address['address'],
            'address_entrance'  => $address['entrance'] ?? null,
            'address_apartment' => $address['apartment'] ?? null,
            'address_floor'     => $address['floor'] ?? null,
            'delivery_comment'  => $lines ? implode("\n", $lines) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
