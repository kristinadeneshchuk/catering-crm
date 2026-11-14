<?php

namespace App\Services\Kitchen;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Індивідуальні замовлення, у яких на день доставки не прописано жодної страви.
 *
 * Таке замовлення не має свого меню, тож у листах кухні їде за циклічним.
 * Якщо меню мало бути (його стерли разом зі старим замовленням, зсунули дні
 * чи ще не внесли), про це треба дізнатись до готування, а не з етикетки.
 */
class IndividualMenuGaps
{
    /**
     * @return Collection<int, object{order_id:int, client_id:int, client:string, calories:int, date:string}>
     */
    public function between(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DB::table('order_days as od')
            ->join('orders as o', 'o.id', '=', 'od.order_id')
            ->join('clients as c', 'c.id', '=', 'o.client_id')
            ->where('o.menu_type', 'individual')
            ->whereIn('o.status', ['new', 'active'])
            ->whereBetween('od.date', [$from->toDateString(), $to->toDateString()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('order_day_dishes as d')
                ->whereColumn('d.order_id', 'od.order_id')
                ->whereColumn('d.date', 'od.date'))
            ->orderBy('od.date')->orderBy('c.name')
            ->get(['o.id as order_id', 'o.client_id', 'c.name as client', 'o.calories', 'od.date'])
            ->map(function ($r) {
                $r->date = substr((string) $r->date, 0, 10);

                return $r;
            });
    }

    /** Текст для Telegram або null, якщо прогалин немає. */
    public function report(CarbonInterface $from, CarbonInterface $to): ?string
    {
        $gaps = $this->between($from, $to);
        if ($gaps->isEmpty()) {
            return null;
        }

        $lines = ['⚠️ <b>Індивідуальні без меню</b>', 'У цих замовленнях на день не прописано страв. Кухня приготує їм циклічне меню, поки меню не внесено.', ''];
        foreach ($gaps->groupBy('order_id') as $orderId => $rows) {
            $first = $rows->first();
            $days  = $rows->map(fn ($r) => \Carbon\Carbon::parse($r->date)->format('d.m'))->implode(', ');
            $lines[] = '• '.e(trim($first->client)).' · '.(int) $first->calories.' ккал · замовлення №'.$orderId.' · '.$days;
        }

        return implode("\n", $lines);
    }
}
