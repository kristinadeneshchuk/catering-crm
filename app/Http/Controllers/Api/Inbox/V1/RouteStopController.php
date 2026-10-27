<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RouteStop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Хто везе замовлення — щоб черговий ШІ на «де доставка?» питав саме того
 * курʼєра, а не всіх підряд. Лише читання знімка route_stops.
 */
class RouteStopController extends Controller
{
    // Середина вікна доставки: за нею обираємо зміну, коли їх кілька.
    private const SHIFT_HOUR = ['morning' => 8, 'evening' => 20];

    public function show(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $now  = Carbon::now('Europe/Kyiv');
        $date = $data['date'] ?? $now->toDateString();

        $hour = $now->hour + $now->minute / 60;
        $stop = RouteStop::with('employee')
            ->where('order_id', $order->id)
            ->whereDate('date', $date)
            ->get()
            ->sortBy(fn (RouteStop $s) => abs((self::SHIFT_HOUR[$s->shift] ?? 99) - $hour))
            ->first();

        if (! $stop) {
            return response()->json(['message' => 'На цю дату замовлення немає в маршрутах.'], 404);
        }

        // Точки того самого маршруту: за ANT-маршрутом, а без нього — курʼєр + зміна.
        $total = RouteStop::whereDate('date', $date)
            ->when($stop->ant_route_id,
                fn ($q) => $q->where('ant_route_id', $stop->ant_route_id),
                fn ($q) => $q->where('employee_id', $stop->employee_id)->where('shift', $stop->shift))
            ->count();

        return response()->json(['data' => [
            'date'        => $date,
            'shift'       => $stop->shift,
            'position'    => $stop->position,
            'stops_total' => $total,
            'address'     => $stop->address,
            'courier'     => [
                'employee_id'           => $stop->employee_id,
                'name'                  => $stop->courier_name ?: $stop->employee?->name ?: $stop->driver_name,
                'phone'                 => $stop->courier_phone ?: $stop->employee?->phone,
                'car_number'            => $stop->car_number,
                'inbox_conversation_id' => $stop->employee?->inbox_conversation_id,
            ],
        ]]);
    }
}
