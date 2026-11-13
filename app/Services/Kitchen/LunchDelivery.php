<?php

namespace App\Services\Kitchen;

use App\Models\LunchOrder;
use App\Support\SchemaReady;
use Carbon\Carbon;

/**
 * Корпоративні обіди як точки доставки. Обід везуть у день обіду, у вікно, яке
 * компанія задала в Lunch Hub; зміна — за початком вікна (до 15:00 — ранкова).
 *
 * Точкою стає лише компанія, привʼязана до клієнта CRM з адресою: без цього
 * курʼєру нікуди їхати, і такі компанії повертаються в списку «пропущено».
 */
final class LunchDelivery
{
    /** Початок вікна до цієї години — ранкова зміна. */
    private const EVENING_FROM_HOUR = 15;

    /**
     * @return array{
     *   points: array<int, array{client:\App\Models\Client, address:?\App\Models\ClientAddress, company:string, sets:int, window:array{0:string,1:string}, contact:?string, comment:?string}>,
     *   skipped: array<int, array{client_id:int, client_name:string, reason:string}>
     * }
     */
    public static function forDelivery(string $deliveryDate, string $shift = 'all'): array
    {
        $out = ['points' => [], 'skipped' => []];
        if (! SchemaReady::has('lunch_orders')) {
            return $out;
        }

        $orders = LunchOrder::with('client.addresses')
            ->whereDate('date', Carbon::parse($deliveryDate)->format('Y-m-d'))
            ->where('sets', '>', 0)
            ->orderBy('company_name')
            ->get();

        foreach ($orders as $o) {
            $window = self::window($o->delivery_window);
            if ($shift !== 'all' && $shift !== self::shiftOf($window)) {
                continue;
            }

            $client = $o->client;
            if (! $client) {
                $out['skipped'][] = ['client_id' => 0, 'client_name' => 'Обіди: '.$o->company_name, 'reason' => 'lunch_company_not_linked'];

                continue;
            }

            $addresses = $client->addresses;
            $address   = $addresses->firstWhere('is_default', true) ?? ($addresses->count() === 1 ? $addresses->first() : null);
            if (! $address && $addresses->count() > 1) {
                $out['skipped'][] = ['client_id' => (int) $client->id, 'client_name' => 'Обіди: '.$client->name, 'reason' => 'multiple_addresses_no_default'];

                continue;
            }
            if (! $address && trim((string) $client->address) === '') {
                $out['skipped'][] = ['client_id' => (int) $client->id, 'client_name' => 'Обіди: '.$client->name, 'reason' => 'lunch_client_without_address'];

                continue;
            }

            $out['points'][] = [
                'client'  => $client,
                'address' => $address,
                'company' => (string) $o->company_name,
                'sets'    => (int) $o->sets,
                'window'  => $window,
                'contact' => $o->contact,
                'comment' => $o->comment,
            ];
        }

        return $out;
    }

    /** Вікно доставки з рядка Lunch Hub («08:30–09:30») → ['08:30', '09:30']. */
    public static function window(?string $raw): array
    {
        if (preg_match('/(\d{1,2}):(\d{2})\s*[–—-]\s*(\d{1,2}):(\d{2})/u', (string) $raw, $m)) {
            return [sprintf('%02d:%s', $m[1], $m[2]), sprintf('%02d:%s', $m[3], $m[4])];
        }

        // Без вікна — обід до полудня: краще привезти зарано, ніж після обідньої перерви.
        return ['09:00', '12:00'];
    }

    public static function shiftOf(array $window): string
    {
        return (int) substr($window[0], 0, 2) >= self::EVENING_FROM_HOUR ? 'evening' : 'morning';
    }

    /**
     * Клієнти-компанії з обідами від сьогодні: їхні адреси мають бути в довіднику
     * ANT, навіть якщо звичайних замовлень у них немає.
     *
     * @return array<int, string> client_id => 'HH:MM-HH:MM'
     */
    public static function upcomingClientWindows(): array
    {
        if (! SchemaReady::has('lunch_orders')) {
            return [];
        }

        return LunchOrder::query()
            ->whereNotNull('client_id')
            ->whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->get(['client_id', 'delivery_window'])
            ->unique('client_id')
            ->mapWithKeys(fn (LunchOrder $o) => [(int) $o->client_id => implode('-', self::window($o->delivery_window))])
            ->all();
    }
}
