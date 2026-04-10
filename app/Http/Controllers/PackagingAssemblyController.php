<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderDay;
use App\Models\Packaging;
use App\Models\Setting;
use App\Models\DailyMenu;
use App\Services\PackagingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PackagingAssemblyController extends Controller
{
    public function __construct(private PackagingService $packagingService) {}

    public function index(Request $request)
    {
        $date = $request->input('date', Carbon::tomorrow()->format('Y-m-d'));
        $targetDate = Carbon::parse($date);

        // Меню на цю дату (цикл)
        $cycleDays    = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate   = Carbon::parse($startDateStr)->startOfDay();
        $diff         = abs($targetDate->startOfDay()->diffInDays($anchorDate));
        $dayNum       = ($diff % $cycleDays) + 1;

        $menu = DailyMenu::with([
            'menuItems.dish',
            'menuItems.mealType',
        ])->where('day_number', $dayNum)->first();

        if (!$menu) {
            return view('kitchen.packaging-assembly', [
                'date'        => $date,
                'menu'        => null,
                'summary'     => [],
                'perClient'   => [],
                'totalCost'   => 0,
            ]);
        }

        // Всі замовлення на цю дату
        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', fn ($q) => $q->where('date', $date))
            ->with([
                'client.mealTypes',
                'orderDays' => fn ($q) => $q->where('date', $date),
                'projectData',
            ])
            ->get();

        // Усі пакувальні матеріали з проставленим типом
        $allPackaging = Packaging::whereNotNull('packaging_type')->get()->keyBy('id');

        // Зведений список по всіх замовленнях
        $summary = $this->packagingService->getDailyPackagingSummary($orders, $menu, $allPackaging);

        // Деталізація по кожному клієнту
        $perClient = [];
        foreach ($orders as $order) {
            if (!$order->client) continue;

            $breakdown = $this->packagingService->getOrderPackagingBreakdown($order, $menu, $allPackaging);
            if (empty($breakdown)) continue;

            $orderDay = $order->orderDays->first();

            $perClient[] = [
                'client_id'      => $order->client->id,
                'client_name'    => $order->client->name,
                'calories'       => (int) $order->calories,
                'project'        => $order->projectData?->name ?? ($order->project ?? '—'),
                'project_slug'   => $order->project ?? 'none',
                'address'        => $orderDay?->address ?? '—',
                'items'          => $breakdown,
                'total_cost'     => collect($breakdown)->sum('total_price'),
            ];
        }

        // Сортуємо по бренду, потім по імені клієнта
        usort($perClient, fn ($a, $b) =>
            strcmp($a['project_slug'] . $a['client_name'], $b['project_slug'] . $b['client_name'])
        );

        $totalCost = collect($summary)->sum('total_cost');

        return view('kitchen.packaging-assembly', compact(
            'date', 'menu', 'summary', 'perClient', 'totalCost'
        ));
    }
}
