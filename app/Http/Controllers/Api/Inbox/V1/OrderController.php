<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\Project;
use App\Models\Tariff;
use App\Services\Inbox\PricingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Створення замовлення з Telegram Inbox.
 *
 * Суму рахуємо наново тим самим PricingService, що і /quotes — quote ніде не
 * зберігається, тож єдина гарантія однакової ціни це однакова формула.
 */
class OrderController extends Controller
{
    /** Ранкова / вечірня доставка. У CRM це schedule_type, а не окреме поле. */
    protected const WINDOWS = [
        'morning' => 'every_day_morning',
        'evening' => 'every_day_evening',
    ];

    public function store(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'project_id'               => ['required', 'integer', 'exists:projects,id'],
            'client_id'                => ['required', 'integer', 'exists:clients,id'],
            'tariff_id'                => ['required', 'integer'],
            'calories'                 => ['required', 'integer', 'min:1'],
            'days'                     => ['required', 'integer', 'min:1'],
            'start_date'               => ['required', 'date'],
            'delivery_window'          => ['nullable', 'in:morning,evening'],
            'delivery_time'            => ['nullable', 'string', 'max:32'],
            'delivery_days'            => ['nullable', 'array'],
            'delivery_days.*'          => ['date'],
            'discount'                 => ['nullable'],
            'discount_reason'          => ['nullable', 'string', 'max:255'],
            'comment'                  => ['nullable', 'string'],
            'source'                   => ['nullable', 'string', 'max:64'],
            'external_conversation_id' => ['nullable', 'integer'],
            'address'                  => ['nullable', 'array'],
            'address.address'          => ['nullable', 'string'],
            'address.entrance'         => ['nullable', 'string', 'max:32'],
            'address.apartment'        => ['nullable', 'string', 'max:32'],
            'address.floor'            => ['nullable', 'string', 'max:32'],
            'address.intercom'         => ['nullable', 'string', 'max:64'],
            'address.delivery_comment' => ['nullable', 'string'],
            'address.handoff'          => ['nullable', 'string'],
        ]);

        $project = Project::findOrFail($data['project_id']);
        $client  = Client::findOrFail($data['client_id']);
        $tariff  = $this->tariffForProject($data['tariff_id'], $project);

        $dates = $this->deliveryDates($data);
        $discount = $pricing->normalizeDiscount($data['discount'] ?? null);

        // Рахуємо ДО створення: якщо ціни нема, замовлення взагалі не має з'явитись.
        $quote = $pricing->quote($tariff, (int) $data['calories'], count($dates), $discount);

        $order = DB::transaction(function () use ($data, $client, $project, $tariff, $dates, $discount) {
            $order = Order::create([
                'client_id'       => $client->id,
                'project'         => $project->slug,
                'tariff_id'       => $tariff->id,
                'calories'        => (int) $data['calories'],
                'duration'        => count($dates),
                'start_date'      => $dates[0],
                'end_date'        => end($dates),
                'scale_factor'    => 1.0,
                'schedule_type'   => self::WINDOWS[$data['delivery_window'] ?? 'morning'],
                'delivery_time'   => $data['delivery_time'] ?? null,
                'discount_type'   => $discount['type'],
                'discount_value'  => $discount['value'],
                'discount_reason' => $data['discount_reason'] ?? null,
                'comment'         => $this->buildComment($data),
            ]);

            // Дні створюємо так само, як CreateOrder::afterCreate — без них
            // замовлення не потрапляє ні у виробництво, ні в логістику.
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

        return response()->json([
            'order' => [
                'id'             => $order->id,
                'project'        => $order->project,
                'status'         => $order->status,
                'payment_status' => $order->is_paid ? 'paid' : 'unpaid',
                'is_paid'        => (bool) $order->is_paid,
                'client_id'      => $client->id,
                'client_balance' => (float) $client->refresh()->balance,
                'calories'       => (int) $order->calories,
                'days'           => (int) $order->duration,
                'start_date'     => $dates[0],
                'end_date'       => end($dates),
                'price_per_day'  => (float) $order->price_per_day,
                'subtotal'       => (float) $order->total_price,
                'discount'       => (float) $order->discount_amount,
                'total'          => (float) ($order->final_price ?? $order->total_price),
                'calorie_range'  => $quote['calorie_range'],
            ],
        ], 201);
    }

    /**
     * Дати доставки: або явний список від Inbox («рвані» дні), або підряд від
     * start_date. Дублікати прибираємо, бо days має дорівнювати реальній
     * кількості днів — інакше сума розійдеться з кількістю OrderDay.
     *
     * @return array<int, string>
     */
    protected function deliveryDates(array $data): array
    {
        if (! empty($data['delivery_days'])) {
            $dates = collect($data['delivery_days'])
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

        $start = Carbon::parse($data['start_date']);

        return collect(range(0, (int) $data['days'] - 1))
            ->map(fn (int $i) => $start->copy()->addDays($i)->toDateString())
            ->all();
    }

    protected function tariffForProject(int $tariffId, Project $project): Tariff
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
     * Адреса на день. Якщо Inbox прислав адресу — ставимо її на кожен день
     * замовлення, щоб курʼєр не залежав від того, яка адреса в клієнта дефолтна.
     */
    protected function dayAddress(array $address): array
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

    /**
     * Слід джерела в коментарі — щоб у CRM було видно, що замовлення прийшло з
     * Telegram, і можна було знайти діалог.
     */
    protected function buildComment(array $data): ?string
    {
        $parts = array_filter([
            $data['comment'] ?? null,
            ($data['source'] ?? null) === 'telegram_inbox' ? 'Оформлено через Telegram Inbox' : null,
            isset($data['external_conversation_id']) ? "Діалог #{$data['external_conversation_id']}" : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }
}
