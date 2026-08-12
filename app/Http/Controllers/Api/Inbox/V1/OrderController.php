<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Services\Inbox\OrderCreator;
use App\Services\Inbox\PricingService;
use App\Services\Inbox\WebhookNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Створення замовлення з зовнішньої системи листування.
 *
 * Уся робота — в OrderCreator, спільному з карткою замовлення в чатах CRM.
 * Сума рахується наново тим самим PricingService, що і /quotes: quote ніде не
 * зберігається, тож єдина гарантія однакової ціни — однакова формула.
 */
class OrderController extends Controller
{
    public function store(Request $request, OrderCreator $creator, PricingService $pricing): JsonResponse
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
        $tariff  = $creator->tariffForProject($data['tariff_id'], $project);

        $result = $creator->create([
            'client'          => $client,
            'project'         => $project,
            'tariff'          => $tariff,
            'calories'        => (int) $data['calories'],
            'dates'           => $creator->resolveDates(
                $data['start_date'],
                (int) $data['days'],
                $data['delivery_days'] ?? null,
            ),
            'discount'        => $pricing->normalizeDiscount($data['discount'] ?? null),
            'delivery_window' => $data['delivery_window'] ?? null,
            'delivery_time'   => $data['delivery_time'] ?? null,
            'discount_reason' => $data['discount_reason'] ?? null,
            'source'          => WebhookNotifier::SOURCE_INBOX,
            'comment'         => $this->buildComment($data),
            'address'         => $data['address'] ?? [],
        ]);

        $order = $result['order'];

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
                'start_date'     => $order->start_date?->toDateString(),
                'end_date'       => $order->end_date?->toDateString(),
                'price_per_day'  => (float) $order->price_per_day,
                'subtotal'       => (float) $order->total_price,
                'discount'       => (float) $order->discount_amount,
                'total'          => (float) ($order->final_price ?? $order->total_price),
                'calorie_range'  => $result['quote']['calorie_range'],
            ],
        ], 201);
    }

    /**
     * Слід джерела в коментарі — щоб у CRM було видно, звідки замовлення, і
     * можна було знайти діалог.
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
