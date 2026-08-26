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

        // Усі активні замовлення на цю дату
        $orders = Order::feedingOn($date)
            ->with([
                'client.mealTypes',
                'client.dishExclusions',
                'menuPlan',
                'replacements.replacementDish',
                'orderDays' => fn ($q) => $q->where('date', $date),
                'projectData',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return view('kitchen.packaging-assembly', [
                'date'      => $date,
                'menu'      => null,
                'summary'   => [],
                'perClient' => [],
                'totalCost' => 0,
            ]);
        }

        $allPackaging = Packaging::whereNotNull('packaging_type')->get()->keyBy('id');

        // Групуємо замовлення по планах меню — кожен план має свій день циклу і своє меню
        $ordersByPlan = $orders->groupBy(fn ($o) => $o->effectiveMenuPlan()?->id ?? 0);

        $summary = []; // зведений список — підсумки по упаковці складаємо з усіх планів
        $perClient = [];
        $primaryMenu = null; // для зворотної сумісності view — лишаємо одне меню (для шапки)

        foreach ($ordersByPlan as $planId => $planOrders) {
            $plan = $planOrders->first()->effectiveMenuPlan();
            if (!$plan) continue;

            $dayNum = $plan->globalDayFor($date);

            $menu = DailyMenu::with([
                'menuItems.dish.dishIngredients.childDish',
                'menuItems.mealType',
            ])
                ->where('menu_plan_id', $plan->id)
                ->where('day_number', $dayNum)
                ->first();
            if (!$menu) continue;

            $primaryMenu = $primaryMenu ?? $menu;

            // Зведений список цього плану — додаємо до глобального
            $planSummary = $this->packagingService->getDailyPackagingSummary($planOrders, $menu, $allPackaging, $date);
            foreach ($planSummary as $packagingId => $row) {
                if (!isset($summary[$packagingId])) {
                    $summary[$packagingId] = $row;
                } else {
                    $summary[$packagingId]['total_qty']   = ($summary[$packagingId]['total_qty'] ?? 0) + ($row['total_qty'] ?? 0);
                    $summary[$packagingId]['total_cost']  = ($summary[$packagingId]['total_cost'] ?? 0) + ($row['total_cost'] ?? 0);
                    $summary[$packagingId]['total_price'] = ($summary[$packagingId]['total_price'] ?? 0) + ($row['total_price'] ?? 0);
                }
            }

            foreach ($planOrders as $order) {
                if (!$order->client) continue;

                $breakdown = $this->packagingService->getOrderPackagingBreakdown($order, $menu, $allPackaging, $date);
                if (empty($breakdown)) continue;

                $orderDay = $order->orderDays->first();

                $perClient[] = [
                    'client_id'    => $order->client->id,
                    'client_name'  => $order->client->name,
                    'calories'     => (int) $order->calories,
                    'project'      => $order->projectData?->name ?? ($order->project ?? '—'),
                    'project_slug' => $order->project ?? 'none',
                    'address'      => $orderDay?->address ?? '—',
                    'items'        => $breakdown,
                    'total_cost'   => collect($breakdown)->sum('total_price'),
                    'plan_name'    => $plan->name,
                ];
            }
        }

        // Сортуємо по бренду, потім по імені клієнта
        usort($perClient, fn ($a, $b) =>
            strcmp($a['project_slug'] . $a['client_name'], $b['project_slug'] . $b['client_name'])
        );

        $totalCost = collect($summary)->sum('total_cost');

        return view('kitchen.packaging-assembly', [
            'date'      => $date,
            'menu'      => $primaryMenu,
            'summary'   => $summary,
            'perClient' => $perClient,
            'totalCost' => $totalCost,
        ]);
    }
}
