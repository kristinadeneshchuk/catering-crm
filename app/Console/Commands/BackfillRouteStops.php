<?php

namespace App\Console\Commands;

use App\Models\DeliveryRoute;
use App\Models\OrderDay;
use App\Models\RouteStop;
use App\Services\AntLogisticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Відновлює архів точок з полів, які досі лежать на order_days.
 *
 * Історія ще не втрачена повністю: 7780 order_days зберігають ant_route_num /
 * ant_route_id / ant_driver. Просто дістатись до неї нічим — штатний шлях
 * (collectOrderDaysForDelivery) бачить лише замовлення в статусах active/new, а
 * там уже 7195 з 7780. Тут ми обходимо це навпростець і переносимо все в
 * route_stops, поки замовлення не почали видаляти.
 *
 * Запускається один раз після міграції. Повторний запуск безпечний:
 * updateOrCreate по (дата, маршрут, клієнт).
 */
class BackfillRouteStops extends Command
{
    protected $signature = 'routes:backfill-stops {--from= : з якої дати їжі} {--dry-run}';
    protected $description = 'Переносить історію точок з order_days у route_stops';

    public function handle(AntLogisticsService $ant): int
    {
        $dry = (bool) $this->option('dry-run');

        $query = OrderDay::query()
            ->whereNotNull('ant_route_num')
            ->with(['order.client.addresses']);

        if ($from = $this->option('from')) {
            $query->where('date', '>=', $from);
        }

        $total = $query->count();
        $this->info("Днів з маршрутом: {$total}");

        // Шапки маршрутів — щоб дістати курʼєра, авто і зміну.
        $routes  = DeliveryRoute::with('employee')->get();
        $byAntId = $routes->filter(fn ($r) => $r->ant_route_id)->keyBy(fn ($r) => (string) $r->ant_route_id);

        // Легасі-ключ. ant_route_id зʼявився пізніше і стоїть лише на 267 днях
        // з 7809, а от водій записаний скрізь. Номер маршруту не унікальний у
        // межах дня (ранковий і вечірній прогони обидва нумеруються з 1), тому
        // додаємо водія — та сама пара, що в DeliveryRoute::extraDeliveryFee().
        $byNumDriver = $routes
            ->filter(fn ($r) => $r->ant_route_num && trim((string) $r->driver_name) !== '')
            ->groupBy(fn ($r) => Carbon::parse($r->date)->format('Y-m-d')
                . '|' . (int) $r->ant_route_num
                . '|' . mb_strtolower(trim((string) $r->driver_name)));

        $created = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar($total);

        $matched = 0;

        $query->chunkById(500, function ($days) use (&$created, &$skipped, &$matched, $byAntId, $byNumDriver, $ant, $dry, $bar) {
            foreach ($days as $day) {
                $bar->advance();

                $client = $day->order?->client;

                if (! $client) {
                    $skipped++;
                    continue;
                }

                // Дата ДОСТАВКИ, а не дата їжі: вечірні замовлення їдуть напередодні.
                try {
                    $deliveryDate = $day->resolveDeliveryDate()->format('Y-m-d');
                } catch (\Throwable $e) {
                    $skipped++;
                    continue;
                }

                $routeId = $day->ant_route_id ? (string) $day->ant_route_id : null;
                $header  = $routeId ? $byAntId->get($routeId) : null;

                if (! $header && $day->ant_driver) {
                    $key        = $deliveryDate . '|' . (int) $day->ant_route_num . '|' . mb_strtolower(trim((string) $day->ant_driver));
                    $candidates = $byNumDriver->get($key);

                    // Тільки однозначний збіг. Двох кандидатів розводити нічим —
                    // краще лишити точку без авто, ніж приписати їй чуже.
                    if ($candidates && $candidates->count() === 1) {
                        $header  = $candidates->first();
                        $routeId ??= $header->ant_route_id ? (string) $header->ant_route_id : null;
                    }
                }

                if ($header) {
                    $matched++;
                }

                $courier = $header?->employee;

                if ($dry) {
                    $created++;
                    continue;
                }

                RouteStop::updateOrCreate(
                    ['date' => $deliveryDate, 'ant_route_id' => $routeId, 'client_id' => $client->id],
                    [
                        'shift'             => $header?->realShift(),
                        'delivery_route_id' => $header?->id,
                        'ant_route_num'     => $day->ant_route_num,
                        'position'          => $day->ant_route_pos,
                        'employee_id'       => $courier?->id,
                        'driver_name'       => $day->ant_driver,
                        'courier_name'      => $courier?->name,
                        'courier_phone'     => $courier?->phone,
                        'car_number'        => $header?->registration_number,
                        'client_name'       => $client->name,
                        'client_phone'      => $client->phone,
                        'address'           => $ant->buildClientAddress($client),
                        'order_id'          => $day->order?->id,
                        'order_day_id'      => $day->id,
                        'source'            => RouteStop::SOURCE_BACKFILL,
                    ]
                );

                $created++;
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(($dry ? 'Було б перенесено: ' : 'Перенесено: ') . $created);
        $this->line("Знайшли шапку маршруту (курʼєр + авто): {$matched}");
        $this->line("Пропущено (без клієнта або без дати доставки): {$skipped}");
        $this->line('Усього в архіві: ' . RouteStop::count());

        return self::SUCCESS;
    }
}
