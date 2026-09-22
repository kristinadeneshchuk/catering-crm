<?php

namespace App\Services\Ops;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Цифри тижня для звіту операційного директора — лише SQL по CRM.
 * Кожен блок — окремий метод, щоб звіт не падав цілком, якщо якоїсь
 * таблиці ще немає.
 */
class WeeklyNumbers
{
    private const ACTIVE = ['active', 'new', 'finished', 'completed', 'paused'];

    public function forWeek(CarbonInterface $from, CarbonInterface $to): array
    {
        $prevFrom = $from->copy()->subWeek();
        $prevTo   = $to->copy()->subWeek();

        return [
            'period'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'volume'    => ['now' => $this->volume($from, $to), 'prev' => $this->volume($prevFrom, $prevTo)],
            'by_day'    => $this->byDay($from, $to),
            'clients'   => ['now' => $this->clients($from, $to), 'prev' => $this->clients($prevFrom, $prevTo)],
            'renewals'  => ['now' => $this->renewals($from, $to), 'prev' => $this->renewals($prevFrom, $prevTo)],
            'expiring'  => $this->expiring($to),
            'kitchen'   => $this->kitchen($from, $to),
            'ratings'   => $this->ratings($from, $to),
            'couriers'  => ['now' => $this->couriers($from, $to), 'prev' => $this->couriers($prevFrom, $prevTo)],
            'purchases' => ['now' => $this->purchases($from, $to), 'prev' => $this->purchases($prevFrom, $prevTo)],
            'money'     => $this->money($from, $to, $prevFrom, $prevTo),
        ];
    }

    private function volume(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('order_days')
            ->join('orders', 'orders.id', '=', 'order_days.order_id')
            ->whereBetween('order_days.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('orders.status', self::ACTIVE)
            ->groupBy('orders.project')
            ->selectRaw('orders.project, COUNT(*) portions, COUNT(DISTINCT orders.client_id) clients')
            ->selectRaw("SUM(CASE WHEN orders.menu_type = 'individual' THEN 1 ELSE 0 END) individual")
            ->selectRaw('ROUND(SUM((orders.total_price - IFNULL(orders.discount_amount, 0)) / (CASE WHEN orders.duration > 0 THEN orders.duration ELSE 1 END))) revenue')
            ->get();

        return [
            'portions'   => (int) $rows->sum('portions'),
            'clients'    => (int) $rows->sum('clients'),
            'individual' => (int) $rows->sum('individual'),
            'revenue'    => (float) $rows->sum('revenue'),
            'by_brand'   => $rows->mapWithKeys(fn ($r) => [$r->project => (int) $r->portions])->all(),
        ];
    }

    private function byDay(CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table('order_days')
            ->join('orders', 'orders.id', '=', 'order_days.order_id')
            ->whereBetween('order_days.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('orders.status', self::ACTIVE)
            ->groupBy('order_days.date')
            ->orderBy('order_days.date')
            ->selectRaw('order_days.date, COUNT(*) n')
            ->pluck('n', 'date')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function clients(CarbonInterface $from, CarbonInterface $to): array
    {
        $firstDays = DB::table('order_days')
            ->join('orders', 'orders.id', '=', 'order_days.order_id')
            ->groupBy('orders.client_id')
            ->selectRaw('orders.client_id, MIN(order_days.date) first_day')
            ->havingBetween('first_day', [$from->toDateString(), $to->toDateString()])
            ->get();

        $trials = 0;

        foreach ($firstDays as $row) {
            $duration = DB::table('orders')->where('client_id', $row->client_id)->orderBy('start_date')->value('duration');
            $trials  += ((int) $duration <= 3) ? 1 : 0;
        }

        return ['new' => $firstDays->count(), 'trials' => $trials];
    }

    private function renewals(CarbonInterface $from, CarbonInterface $to): array
    {
        $ended = DB::table('orders')
            ->whereBetween('end_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', ['active', 'finished', 'completed'])
            ->get(['id', 'client_id', 'end_date']);

        $renewed = 0;

        foreach ($ended as $order) {
            $renewed += DB::table('orders')
                ->where('client_id', $order->client_id)
                ->where('start_date', '>', $order->end_date)
                ->where('start_date', '<=', date('Y-m-d', strtotime($order->end_date.' +7 days')))
                ->exists() ? 1 : 0;
        }

        return ['ended' => $ended->count(), 'renewed' => $renewed,
            'rate' => $ended->count() ? round($renewed / $ended->count() * 100) : null];
    }

    private function expiring(CarbonInterface $to): array
    {
        $from = $to->copy()->addDay()->toDateString();
        $till = $to->copy()->addDays(3)->toDateString();

        $rows = DB::table('orders')->whereBetween('end_date', [$from, $till])->whereIn('status', ['active', 'new'])
            ->selectRaw('COUNT(*) orders, COUNT(DISTINCT client_id) clients')->first();

        return ['orders' => (int) $rows->orders, 'clients' => (int) $rows->clients, 'from' => $from, 'till' => $till];
    }

    /** Кухня готує на завтра: ФОТ дня D ділимо на порції дня D+1 (пʼятниця — сб+нд). */
    private function kitchen(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! \App\Support\SchemaReady::has('employee_shifts')) {
            return [];
        }

        $shifts = DB::table('employee_shifts')
            ->join('employees', 'employees.id', '=', 'employee_shifts.employee_id')
            ->whereBetween('employee_shifts.date', [$from->toDateString(), $to->toDateString()])
            ->where('employee_shifts.is_planned', false)
            ->where(fn ($q) => $q->whereIn('employees.position', ['cook', 'chef', 'assistant', 'packer'])
                ->orWhereIn('employee_shifts.position_key', ['cook', 'chef', 'assistant', 'packer']))
            ->groupBy('employee_shifts.date')
            ->selectRaw('employee_shifts.date, COUNT(*) people, SUM(employee_shifts.rate) fot')
            ->get()->keyBy('date');

        $portions = $this->byDay($from, $to->copy()->addDay());
        $days     = [];

        foreach ($shifts as $date => $row) {
            $cook = \Carbon\Carbon::parse($date);
            $for  = $cook->isFriday()
                ? ($portions[$cook->copy()->addDay()->toDateString()] ?? 0) + ($portions[$cook->copy()->addDays(2)->toDateString()] ?? 0)
                : ($portions[$cook->copy()->addDay()->toDateString()] ?? 0);

            $days[$date] = ['people' => (int) $row->people, 'fot' => (float) $row->fot, 'portions' => $for,
                'per_portion' => $for > 0 ? round($row->fot / $for) : null];
        }

        $fot = array_sum(array_column($days, 'fot'));
        $for = array_sum(array_column($days, 'portions'));

        return ['days' => $days, 'fot' => $fot, 'portions' => $for, 'per_portion' => $for > 0 ? round($fot / $for) : null,
            'limit' => (float) config('ops.kitchen_fot_per_portion', 130)];
    }

    private function ratings(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! \App\Support\SchemaReady::has('dish_ratings')) {
            return [];
        }

        $all = DB::table('dish_ratings')->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        $worst = DB::table('dish_ratings')
            ->join('dishes', 'dishes.id', '=', 'dish_ratings.dish_id')
            ->whereBetween('dish_ratings.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('dishes.name')
            ->selectRaw('dishes.name, COUNT(*) n, ROUND(AVG(dish_ratings.stars), 1) avg_stars')
            ->orderBy('avg_stars')->orderByDesc('n')
            ->limit(3)->get();

        $comments = DB::table('dish_ratings')
            ->join('dishes', 'dishes.id', '=', 'dish_ratings.dish_id')
            ->whereBetween('dish_ratings.date', [$from->toDateString(), $to->toDateString()])
            ->where('dish_ratings.stars', '<=', 3)
            ->whereNotNull('dish_ratings.comment')
            ->orderBy('dish_ratings.stars')
            ->limit(3)
            ->get(['dishes.name', 'dish_ratings.stars', 'dish_ratings.comment']);

        return [
            'count'    => (clone $all)->count(),
            'avg'      => round((float) (clone $all)->avg('stars'), 2),
            'bad'      => (clone $all)->where('stars', '<=', 2)->count(),
            'worst'    => $worst->all(),
            'comments' => $comments->all(),
        ];
    }

    private function couriers(CarbonInterface $from, CarbonInterface $to): array
    {
        $out = ['routes' => 0, 'stops' => 0, 'km' => 0, 'compensation' => 0.0, 'pay' => 0.0, 'couriers' => 0];

        if (\App\Support\SchemaReady::has('delivery_routes')) {
            $r = DB::table('delivery_routes')->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('COUNT(*) routes, SUM(count_comps) stops')->first();
            $out['routes'] = (int) $r->routes;
            $out['stops']  = (int) $r->stops;
        }

        if (\App\Support\SchemaReady::has('courier_mileage_logs')) {
            $logs = \App\Models\CourierMileageLog::whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
            $out['km']           = (int) $logs->sum('km');
            $out['compensation'] = round((float) $logs->sum('compensation'));
            $out['couriers']     = $logs->pluck('employee_id')->unique()->count();
        }

        if (\App\Support\SchemaReady::has('employee_shifts')) {
            $out['pay'] = (float) DB::table('employee_shifts')
                ->join('employees', 'employees.id', '=', 'employee_shifts.employee_id')
                ->where('employees.position', 'courier')
                ->where('employee_shifts.is_planned', false)
                ->whereBetween('employee_shifts.date', [$from->toDateString(), $to->toDateString()])
                ->sum('employee_shifts.rate');
        }

        $out['total']    = $out['compensation'] + $out['pay'];
        $out['per_stop'] = $out['stops'] > 0 ? round($out['total'] / $out['stops']) : null;

        return $out;
    }

    private function purchases(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! \App\Support\SchemaReady::has('stock_documents')) {
            return [];
        }

        $posted = DB::table('stock_documents')->where('type', 'receipt')->where('status', '!=', 'draft')
            ->whereBetween('operation_date', [$from->startOfDay(), $to->copy()->endOfDay()]);

        $bySupplier = DB::table('stock_documents')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'stock_documents.supplier_id')
            ->where('stock_documents.type', 'receipt')->where('stock_documents.status', '!=', 'draft')
            ->whereBetween('stock_documents.operation_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('suppliers.name')
            ->selectRaw("IFNULL(suppliers.name, 'без постачальника') supplier, ROUND(SUM(stock_documents.total_sum)) sum")
            ->orderByDesc('sum')->limit(4)->get();

        return [
            'docs'        => (clone $posted)->count(),
            'sum'         => (float) (clone $posted)->sum('total_sum'),
            'by_supplier' => $bySupplier->all(),
            'drafts'      => DB::table('stock_documents')->where('status', 'draft')->count(),
            'drafts_sum'  => (float) DB::table('stock_documents')->where('status', 'draft')->sum('total_sum'),
        ];
    }

    private function money(CarbonInterface $from, CarbonInterface $to, CarbonInterface $prevFrom, CarbonInterface $prevTo): array
    {
        $paid = fn ($a, $b) => DB::table('transactions')->where('type', 'income')->whereNotNull('order_id')
            ->whereBetween('date', [$a->toDateString(), $b->toDateString()])->selectRaw('COUNT(*) n, ROUND(SUM(amount)) s')->first();

        $now  = $paid($from, $to);
        $prev = $paid($prevFrom, $prevTo);

        $unpaid = DB::table('orders')->where('is_paid', false)->whereIn('status', ['active', 'new'])
            ->where('end_date', '>=', $to->toDateString())->selectRaw('COUNT(*) n, ROUND(SUM(total_price)) s')->first();

        $debt = DB::table('clients')->where('balance', '<', -100)->selectRaw('COUNT(*) n, ROUND(-SUM(balance)) s')->first();

        return [
            'paid_in'      => (float) $now->s, 'payments' => (int) $now->n,
            'paid_in_prev' => (float) $prev->s,
            'unpaid'       => (int) $unpaid->n, 'unpaid_sum' => (float) $unpaid->s,
            'debtors'      => (int) $debt->n, 'debt' => (float) $debt->s,
        ];
    }
}
