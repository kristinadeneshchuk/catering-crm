<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Account;
use App\Models\DeliveryRoute;
use App\Models\OrderDay;
use App\Models\Setting;
use App\Models\DailyMenu;
use App\Models\Ingredient;
use App\Models\Client;
use App\Models\EmployeeShift;
use App\Models\Packaging;
use App\Models\Transaction;
use App\Services\FoodCostService;
use App\Services\PackagingService;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    protected $foodCostService;
    protected $packagingService;

    public function __construct(FoodCostService $foodCostService, PackagingService $packagingService)
    {
        $this->foodCostService  = $foodCostService;
        $this->packagingService = $packagingService;
    }

    public function index(Request $request)
    {
        // 1. Отримуємо параметри запиту
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));

        // 🔥 Динамічні параметри для P&L (брак, інші витрати) та вкладка
        $spoilagePercent = (float) $request->input('spoilage_percent', 7);
        $otherExpenses = (float) $request->input('other_expenses', 1000);
        $activeTab = $request->input('tab', 'dashboard');

        // Оренда та комунальні: місячне значення (розрахунок по днях — після побудови $dates)
        $monthlyRent = (float) \App\Models\Setting::where('key', 'monthly_rent')->value('value');
        $monthlyUtilities = (float) \App\Models\Setting::where('key', 'monthly_utilities')->value('value');

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Обмеження до 60 днів для швидкодії
        if ($start->diffInDays($end) > 60) {
            $end = $start->copy()->addDays(60);
            $endDate = $end->format('Y-m-d');
        }

        // Формуємо масив дат
        $dates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dates[$date->format('Y-m-d')] = $date->format('d.m');
        }

        // Денна ставка оренди/комунальних залежить від реальної кількості днів у місяці кожного дня
        $rentByDay = [];
        $utilitiesByDay = [];
        foreach (array_keys($dates) as $ymd) {
            $daysInMonth = Carbon::parse($ymd)->daysInMonth;
            $rentByDay[$ymd] = $monthlyRent > 0 ? round($monthlyRent / $daysInMonth, 2) : 0;
            $utilitiesByDay[$ymd] = $monthlyUtilities > 0 ? round($monthlyUtilities / $daysInMonth, 2) : 0;
        }

        // 2. Завантаження даних
        // Включаємо всі необхідні зв'язки для FoodCostService та аналітики
        $validDays = OrderDay::whereBetween('date', [$startDate, $endDate])
            ->with([
                'order.client.mealTypes',
                'order.projectData',
                'order.replacements.replacementProduct',
                'order.replacements.replacementDish.dishIngredients.ingredient'
            ])
            ->get();

        $groupedDays = $validDays->groupBy(function ($item) {
            return Carbon::parse($item->date)->format('Y-m-d');
        });

        // 🔥 Зарплати (ФОП) одним запитом за період
        $allShifts = EmployeeShift::whereBetween('date', [$startDate, $endDate])
            ->get()
            ->groupBy('date');

        // Налаштування меню та циклу
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);

        $allMenus = DailyMenu::with([
            'menuItems.dish.dishIngredients.ingredient',
            'menuItems.mealType'
        ])->get()->keyBy('day_number');
        
        $allIngredients = Ingredient::all()->keyBy('id');

        // Витрати на доставку по датах (з таблиці delivery_routes)
        $deliveryRoutes = DeliveryRoute::whereBetween('date', [$startDate, $endDate])
            ->get()
            ->groupBy(fn ($r) => \Carbon\Carbon::parse($r->date)->format('Y-m-d'));
        $deliveryCostByDate = [];
        $totalDeliveryCost  = 0;
        foreach ($deliveryRoutes as $ymd => $dayRoutes) {
            $cost = round((float) $dayRoutes->sum('calculated_cost'));
            $deliveryCostByDate[$ymd] = $cost;
            $totalDeliveryCost += $cost;
        }

        // Завантаження пакувальних матеріалів (тільки з проставленим типом)
        $allPackaging = Packaging::whereNotNull('packaging_type')->get()->keyBy('id');

        // Ініціалізація підсумкових змінних
        $rationsCount = []; $totalRations = 0;
        $revenueCount = []; $totalRevenue = 0;
        $foodCostCount = []; $totalFoodCost = 0;
        $fopCount = []; $totalFop = 0;
        $discountCount = []; $totalDiscount = 0;
        $packagingCount = []; $totalPackagingCost = 0;

        $unitEconomics = [];
        $marketingStats = [];
        $projectStats = [];
        $uniqueClientIds = [];

        // Individual vs Cyclic tracking
        $indClientIds = []; $indRevenue = 0.0; $indFoodCost = 0.0; $indRations = 0; $indCalories = [];
        $cyclicClientIds = []; $cyclicRevenue = 0.0; $cyclicFoodCost = 0.0; $cyclicRations = 0;

        // 3. ОСНОВНИЙ ЦИКЛ ПО ДНЯХ
        foreach ($dates as $ymd => $dm) {
            $days = $groupedDays->get($ymd, collect());

            $count = $days->count();
            $rationsCount[$ymd] = $count;
            $totalRations += $count;

            // ФОП за день
            $dailyFop = $allShifts->has($ymd) ? $allShifts->get($ymd)->sum('rate') : 0;
            $fopCount[$ymd] = round($dailyFop);
            $totalFop += round($dailyFop);

            $dailyRevenue = 0;
            $dailyFoodCost = 0;
            $dailyDiscount = 0;
            $dailyPackaging = 0;

            if ($count > 0) {
                $diff = abs(Carbon::parse($ymd)->diffInDays($anchorDate));
                $dayNum = ($diff % $cycleDays) + 1;
                $menu = $allMenus->get($dayNum);

                foreach ($days as $orderDay) {
                    $order = $orderDay->order;
                    if (!$order || !$order->client) continue;

                    $uniqueClientIds[$order->client->id] = true;

                    $duration = max(1, (int) $order->duration);

                    // Виручка по final_price (net)
                    $basePricePerDay     = (float) $order->total_price / $duration;
                    $orderDiscountPerDay = (float) $order->discount_amount / $duration;
                    $dayDiscount         = (float) $orderDay->discount_amount;
                    $netPricePerDay      = max(0, $basePricePerDay - $orderDiscountPerDay - $dayDiscount);

                    $dailyRevenue  += $netPricePerDay;
                    $dailyDiscount += $orderDiscountPerDay + $dayDiscount;

                    // Food Cost
                    $orderCost = 0;
                    if ($menu) {
                        $orderCost = $this->foodCostService->calculateOrderFoodCost($order, $menu, $allIngredients);
                        $dailyFoodCost += $orderCost;
                    }

                    // Packaging Cost
                    if ($menu && $allPackaging->isNotEmpty()) {
                        $packagingCost = $this->packagingService->calculateOrderPackagingCost($order, $menu, $allPackaging);
                        $dailyPackaging += $packagingCost;
                    }

                    // Individual / Cyclic split
                    if ($order->menu_type === 'individual') {
                        $indClientIds[$order->client->id] = true;
                        $indRevenue   += $netPricePerDay;
                        $indFoodCost  += $orderCost;
                        $indRations++;
                        $indCal = (int) $order->calories;
                        if (!isset($indCalories[$indCal])) {
                            $indCalories[$indCal] = ['count' => 0, 'revenue' => 0.0, 'food_cost' => 0.0, 'clients' => []];
                        }
                        $indCalories[$indCal]['count']++;
                        $indCalories[$indCal]['revenue']   += $netPricePerDay;
                        $indCalories[$indCal]['food_cost'] += $orderCost;
                        $indCalories[$indCal]['clients'][$order->client->id] = true;
                    } else {
                        $cyclicClientIds[$order->client->id] = true;
                        $cyclicRevenue  += $netPricePerDay;
                        $cyclicFoodCost += $orderCost;
                        $cyclicRations++;
                    }

                    // Юніт-економіка
                    $cal = (int) $order->calories;
                    if (!isset($unitEconomics[$cal])) {
                        $unitEconomics[$cal] = ['count' => 0, 'revenue' => 0, 'cost' => 0, 'unique_orders' => []];
                    }
                    $unitEconomics[$cal]['count']   += 1;
                    $unitEconomics[$cal]['revenue'] += $netPricePerDay;
                    $unitEconomics[$cal]['cost']    += $orderCost;
                    $unitEconomics[$cal]['unique_orders'][$order->id] = [
                        'total_price' => (float) $order->final_price,
                        'duration'    => $duration,
                    ];

                    // Маркетинг
                    $source = $order->client->sales_source ?: 'Не вказано';
                    if (!isset($marketingStats[$source])) {
                        $marketingStats[$source] = ['clients_count' => [], 'revenue' => 0, 'orders_count' => 0];
                    }
                    $marketingStats[$source]['revenue']     += $netPricePerDay;
                    $marketingStats[$source]['orders_count'] += 1;
                    $marketingStats[$source]['clients_count'][$order->client->id] = true;

                    // Проєкти
                    $projectSlug = $order->project ?: 'unknown';
                    $projectName = $order->projectData?->name ?? $projectSlug;
                    if (!isset($projectStats[$projectSlug])) {
                        $projectStats[$projectSlug] = [
                            'name'          => $projectName,
                            'rations'       => 0,
                            'revenue'       => 0,
                            'food_cost'     => 0,
                            'packaging'     => 0,
                            'clients'       => [],
                        ];
                    }
                    $projectStats[$projectSlug]['rations']   += 1;
                    $projectStats[$projectSlug]['revenue']   += $netPricePerDay;
                    $projectStats[$projectSlug]['food_cost'] += $orderCost;
                    $projectStats[$projectSlug]['packaging'] += $menu && $allPackaging->isNotEmpty()
                        ? $this->packagingService->calculateOrderPackagingCost($order, $menu, $allPackaging)
                        : 0;
                    $projectStats[$projectSlug]['clients'][$order->client->id] = true;
                }
            }

            $revenueCount[$ymd]    = round($dailyRevenue);
            $totalRevenue          += round($dailyRevenue);
            $foodCostCount[$ymd]   = round($dailyFoodCost);
            $totalFoodCost         += round($dailyFoodCost);
            $discountCount[$ymd]   = round($dailyDiscount);
            $totalDiscount         += round($dailyDiscount);
            $packagingCount[$ymd]  = round($dailyPackaging);
            $totalPackagingCost    += round($dailyPackaging);
        }

        // 4. ПІДРАХУНОК ЮНІТ-ЕКОНОМІКИ (Агрегація)
        ksort($unitEconomics); 
        foreach ($unitEconomics as $cal => &$data) {
            $data['profit'] = $data['revenue'] - $data['cost'];
            $data['margin'] = $data['revenue'] > 0 ? ($data['profit'] / $data['revenue']) * 100 : 0;
            $data['avg_revenue'] = $data['count'] > 0 ? $data['revenue'] / $data['count'] : 0;
            $data['avg_cost'] = $data['count'] > 0 ? $data['cost'] / $data['count'] : 0;
            $data['avg_profit'] = $data['count'] > 0 ? $data['profit'] / $data['count'] : 0;
            $data['revenue_share'] = $totalRevenue > 0 ? ($data['revenue'] / $totalRevenue) * 100 : 0;

            $totalOrderValue = 0; $totalDuration = 0; $orderCount = count($data['unique_orders']);
            foreach ($data['unique_orders'] as $uOrder) {
                $totalOrderValue += $uOrder['total_price'];
                $totalDuration += $uOrder['duration'];
            }
            $data['avg_order_value'] = $orderCount > 0 ? $totalOrderValue / $orderCount : 0;
            $data['avg_duration'] = $orderCount > 0 ? $totalDuration / $orderCount : 0;
        }
        unset($data);

        // 5а. АГРЕГАЦІЯ ПРОЄКТІВ
        foreach ($projectStats as $slug => &$ps) {
            $ps['unique_clients'] = count($ps['clients']);
            unset($ps['clients']);
            $ps['profit']  = $ps['revenue'] - $ps['food_cost'] - $ps['packaging'];
            $ps['margin']  = $ps['revenue'] > 0 ? ($ps['profit'] / $ps['revenue']) * 100 : 0;
            $ps['revenue_share'] = $totalRevenue > 0 ? ($ps['revenue'] / $totalRevenue) * 100 : 0;
        }
        unset($ps);
        uasort($projectStats, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        // 5. ПІДРАХУНОК МАРКЕТИНГУ (Агрегація)
        foreach ($marketingStats as $source => &$stats) {
            $uniqueClientsCount = count($stats['clients_count']);
            $stats['avg_lva'] = $uniqueClientsCount > 0 ? $stats['revenue'] / $uniqueClientsCount : 0;
            $stats['unique_clients'] = $uniqueClientsCount;
            $stats['revenue_share'] = $totalRevenue > 0 ? ($stats['revenue'] / $totalRevenue) * 100 : 0;
        }
        unset($stats);

        // 6. РОЗРАХУНОК RETENTION (Утримання та LTV за всю історію)
        $retentionStats = [
            'total_clients' => 0, 'active_now' => 0, 'churned' => 0,
            'avg_lifetime_days' => 0, 'avg_ltv' => 0, 'churn_rate' => 0,
            'new_clients' => 0, 'new_clients_percent' => 0, 'new_clients_continued' => 0, 'new_clients_churned' => 0,
            'churned_period' => 0, 'churned_period_percent' => 0,
            'segments' => [
                'trial'   => ['count' => 0, 'label' => 'Пробні (1-3 дні)',    'color' => 'bg-rose-500'],
                'regular' => ['count' => 0, 'label' => 'Постійні (4-14 днів)', 'color' => 'bg-blue-500'],
                'vip'     => ['count' => 0, 'label' => 'VIP (15-29 днів)',     'color' => 'bg-avocado-500'],
                'elite'   => ['count' => 0, 'label' => 'Еліт (30+ днів)',      'color' => 'bg-violet-500'],
            ]
        ];

        if (!empty($uniqueClientIds)) {
            $clients = Client::whereIn('id', array_keys($uniqueClientIds))->with('orders')->get();
            $today = Carbon::now()->format('Y-m-d');
            $totalLifetimeDays = 0;
            $totalLtv = 0;

            foreach ($clients as $client) {
                $clientDays = 0; $clientRevenue = 0; $lastOrderEndDate = null; $firstOrderStartDate = null;

                foreach ($client->orders as $o) {
                    if ($o->total_price > 0 && !in_array($o->status, ['cancelled'])) {
                        $clientDays += max(1, (int) $o->duration);
                        // 🔥 LTV рахуємо по final_price (реально сплачена сума)
                        $clientRevenue += (float) ($o->final_price > 0 ? $o->final_price : $o->total_price);
                        if (!$lastOrderEndDate || $o->end_date > $lastOrderEndDate) {
                            $lastOrderEndDate = $o->end_date;
                        }
                        if (!$firstOrderStartDate || $o->start_date < $firstOrderStartDate) {
                            $firstOrderStartDate = $o->start_date;
                        }
                    }
                }

                $totalLifetimeDays += $clientDays;
                $totalLtv += $clientRevenue;
                $retentionStats['total_clients']++;

                // Новий клієнт: перше замовлення (за всю історію) починається в обраному періоді
                $isNewClient = $firstOrderStartDate && $firstOrderStartDate >= $startDate && $firstOrderStartDate <= $endDate;
                if ($isNewClient) {
                    $retentionStats['new_clients']++;
                    if ($lastOrderEndDate && $lastOrderEndDate >= $today) {
                        $retentionStats['new_clients_continued']++;
                    } else {
                        $retentionStats['new_clients_churned']++;
                    }
                }

                if ($lastOrderEndDate && $lastOrderEndDate >= $today) {
                    $retentionStats['active_now']++;
                } else {
                    $retentionStats['churned']++;
                    // Відпав у цьому періоді: остання підписка закінчилась у межах обраного діапазону
                    if ($lastOrderEndDate && $lastOrderEndDate >= $startDate && $lastOrderEndDate <= $endDate) {
                        $retentionStats['churned_period']++;
                    }
                }

                if ($clientDays <= 3) {
                    $retentionStats['segments']['trial']['count']++;
                } elseif ($clientDays <= 14) {
                    $retentionStats['segments']['regular']['count']++;
                } elseif ($clientDays <= 29) {
                    $retentionStats['segments']['vip']['count']++;
                } else {
                    $retentionStats['segments']['elite']['count']++;
                }
            }

            if ($retentionStats['total_clients'] > 0) {
                $retentionStats['avg_lifetime_days'] = $totalLifetimeDays / $retentionStats['total_clients'];
                $retentionStats['avg_ltv'] = $totalLtv / $retentionStats['total_clients'];
                $retentionStats['churn_rate'] = ($retentionStats['churned'] / $retentionStats['total_clients']) * 100;
                $retentionStats['new_clients_percent'] = ($retentionStats['new_clients'] / $retentionStats['total_clients']) * 100;
                $retentionStats['churned_period_percent'] = ($retentionStats['churned_period'] / $retentionStats['total_clients']) * 100;
            }
        }

        // 6б. INDIVIDUAL CLIENTS ANALYTICS
        ksort($indCalories);
        foreach ($indCalories as $indCal => &$cData) {
            $cData['unique_clients'] = count($cData['clients']);
            unset($cData['clients']);
            $cData['profit']          = $cData['revenue'] - $cData['food_cost'];
            $cData['margin']          = $cData['revenue'] > 0 ? ($cData['profit'] / $cData['revenue']) * 100 : 0;
            $cData['revenue_share']   = $indRevenue > 0 ? ($cData['revenue'] / $indRevenue) * 100 : 0;
            $cData['avg_per_ration']  = $cData['count'] > 0 ? $cData['revenue'] / $cData['count'] : 0;
        }
        unset($cData);

        // Unique orders for avg_check / avg_duration
        $indOrdersInPeriod   = $validDays->filter(fn($od) => $od->order && $od->order->menu_type === 'individual')->pluck('order')->unique('id');
        $cyclicOrdersInPeriod = $validDays->filter(fn($od) => $od->order && $od->order->menu_type !== 'individual')->pluck('order')->unique('id');
        $indOrderCount   = $indOrdersInPeriod->count();
        $cyclicOrderCount = $cyclicOrdersInPeriod->count();

        // Retention for individual clients
        $indRetention = [
            'total_clients' => 0, 'active_now' => 0, 'churned' => 0,
            'avg_lifetime_days' => 0, 'avg_ltv' => 0, 'churn_rate' => 0,
            'new_clients' => 0, 'new_clients_continued' => 0, 'new_clients_churned' => 0,
            'churned_period' => 0, 'churned_period_percent' => 0,
            'segments' => [
                'trial'   => ['count' => 0, 'label' => 'Пробні (1-3 дні)',    'color' => 'bg-rose-500'],
                'regular' => ['count' => 0, 'label' => 'Постійні (4-14 днів)', 'color' => 'bg-blue-500'],
                'vip'     => ['count' => 0, 'label' => 'VIP (15-29 днів)',     'color' => 'bg-avocado-500'],
                'elite'   => ['count' => 0, 'label' => 'Еліт (30+ днів)',      'color' => 'bg-violet-500'],
            ],
        ];
        $indLtvTotal = 0; $indLifetimeDaysTotal = 0;

        if (!empty($indClientIds)) {
            $indClients = Client::whereIn('id', array_keys($indClientIds))->with('orders')->get();
            $todayInd   = Carbon::now()->format('Y-m-d');

            foreach ($indClients as $client) {
                $clientDays = 0; $clientRevenue = 0; $lastEnd = null; $firstStart = null;
                foreach ($client->orders as $o) {
                    if ($o->total_price > 0 && !in_array($o->status, ['cancelled'])) {
                        $clientDays    += max(1, (int) $o->duration);
                        $clientRevenue += (float) ($o->final_price > 0 ? $o->final_price : $o->total_price);
                        if (!$lastEnd   || $o->end_date   > $lastEnd)   $lastEnd   = $o->end_date;
                        if (!$firstStart || $o->start_date < $firstStart) $firstStart = $o->start_date;
                    }
                }
                $indLtvTotal          += $clientRevenue;
                $indLifetimeDaysTotal += $clientDays;
                $indRetention['total_clients']++;

                if ($firstStart && $firstStart >= $startDate && $firstStart <= $endDate) {
                    $indRetention['new_clients']++;
                    if ($lastEnd && $lastEnd >= $todayInd) $indRetention['new_clients_continued']++;
                    else $indRetention['new_clients_churned']++;
                }
                if ($lastEnd && $lastEnd >= $todayInd) {
                    $indRetention['active_now']++;
                } else {
                    $indRetention['churned']++;
                    if ($lastEnd && $lastEnd >= $startDate && $lastEnd <= $endDate) $indRetention['churned_period']++;
                }
                if ($clientDays <= 3)      $indRetention['segments']['trial']['count']++;
                elseif ($clientDays <= 14) $indRetention['segments']['regular']['count']++;
                elseif ($clientDays <= 29) $indRetention['segments']['vip']['count']++;
                else                       $indRetention['segments']['elite']['count']++;
            }
            if ($indRetention['total_clients'] > 0) {
                $indRetention['avg_lifetime_days']     = $indLifetimeDaysTotal / $indRetention['total_clients'];
                $indRetention['avg_ltv']               = $indLtvTotal / $indRetention['total_clients'];
                $indRetention['churn_rate']            = ($indRetention['churned'] / $indRetention['total_clients']) * 100;
                $indRetention['churned_period_percent'] = ($indRetention['churned_period'] / $indRetention['total_clients']) * 100;
            }
        }

        // Cyclic-only clients LTV for comparison
        $cyclicOnlyIds = array_diff_key($uniqueClientIds, $indClientIds);
        $cyclicCompare = ['avg_ltv' => 0, 'avg_lifetime_days' => 0, 'churn_rate' => 0, 'total_clients' => 0, 'churned' => 0];
        if (!empty($cyclicOnlyIds)) {
            $cyclicClients  = Client::whereIn('id', array_keys($cyclicOnlyIds))->with('orders')->get();
            $cyclicLtvTotal = 0; $cyclicLifeDays = 0; $todayCyc = Carbon::now()->format('Y-m-d');
            foreach ($cyclicClients as $client) {
                $cDays = 0; $cRev = 0; $cLast = null;
                foreach ($client->orders as $o) {
                    if ($o->total_price > 0 && !in_array($o->status, ['cancelled'])) {
                        $cDays += max(1, (int) $o->duration);
                        $cRev  += (float) ($o->final_price > 0 ? $o->final_price : $o->total_price);
                        if (!$cLast || $o->end_date > $cLast) $cLast = $o->end_date;
                    }
                }
                $cyclicLtvTotal += $cRev;
                $cyclicLifeDays += $cDays;
                $cyclicCompare['total_clients']++;
                if (!$cLast || $cLast < $todayCyc) $cyclicCompare['churned']++;
            }
            if ($cyclicCompare['total_clients'] > 0) {
                $cyclicCompare['avg_ltv']           = $cyclicLtvTotal / $cyclicCompare['total_clients'];
                $cyclicCompare['avg_lifetime_days'] = $cyclicLifeDays / $cyclicCompare['total_clients'];
                $cyclicCompare['churn_rate']        = ($cyclicCompare['churned'] / $cyclicCompare['total_clients']) * 100;
            }
        }

        $individualStats = [
            'clients_count'  => count($indClientIds),
            'revenue'        => round($indRevenue),
            'revenue_share'  => $totalRevenue > 0 ? ($indRevenue / $totalRevenue) * 100 : 0,
            'rations_count'  => $indRations,
            'food_cost'      => round($indFoodCost),
            'margin'         => $indRevenue > 0 ? (($indRevenue - $indFoodCost) / $indRevenue) * 100 : 0,
            'avg_check'      => $indOrderCount > 0 ? $indOrdersInPeriod->sum(fn($o) => (float)$o->final_price) / $indOrderCount : 0,
            'avg_duration'   => $indOrderCount > 0 ? $indOrdersInPeriod->sum('duration') / $indOrderCount : 0,
            'avg_ltv'        => $indRetention['avg_ltv'],
            'calories'       => $indCalories,
            'retention'      => $indRetention,
            'comparison'     => [
                'individual' => [
                    'avg_check'      => $indOrderCount > 0 ? $indOrdersInPeriod->sum(fn($o) => (float)$o->final_price) / $indOrderCount : 0,
                    'avg_ltv'        => $indRetention['avg_ltv'],
                    'avg_duration'   => $indOrderCount > 0 ? $indOrdersInPeriod->sum('duration') / $indOrderCount : 0,
                    'margin'         => $indRevenue > 0 ? (($indRevenue - $indFoodCost) / $indRevenue) * 100 : 0,
                    'churn_rate'     => $indRetention['churn_rate'],
                    'clients_count'  => count($indClientIds),
                ],
                'cyclic' => [
                    'avg_check'      => $cyclicOrderCount > 0 ? $cyclicOrdersInPeriod->sum(fn($o) => (float)$o->final_price) / $cyclicOrderCount : 0,
                    'avg_ltv'        => $cyclicCompare['avg_ltv'],
                    'avg_duration'   => $cyclicOrderCount > 0 ? $cyclicOrdersInPeriod->sum('duration') / $cyclicOrderCount : 0,
                    'margin'         => $cyclicRevenue > 0 ? (($cyclicRevenue - $cyclicFoodCost) / $cyclicRevenue) * 100 : 0,
                    'churn_rate'     => $cyclicCompare['churn_rate'],
                    'clients_count'  => count($cyclicOnlyIds),
                ],
            ],
        ];

        // 7. КАСОВИЙ РОЗРИВ за обраний період
        // Тільки реальні платежі від клієнтів (з прив'язкою до рахунку).
        // Бухгалтерські записи від створення/зміни замовлень не мають account_id → не рахуємо.
        $cashReceivedPeriod = round(Transaction::where('type', 'income')
            ->whereNotNull('account_id')
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount'));

        $cashBalance = round(Account::sum('balance'));

        // Передоплачені, але ще не доставлені раціони (поточний момент)
        // Враховуємо часткові оплати через client.balance
        // Формула: prepaid = max(0, min(future_value, future_value + client.balance))
        // balance = total_income - refunds - all_orders_cost
        // Якщо balance = -200 і future = 500 → клієнт сплатив 300 з 500 майбутніх раціонів
        $today = Carbon::now()->format('Y-m-d');

        $futureOrderDays = OrderDay::where('date', '>', $today)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['active', 'new', 'paused']))
            ->with('order')
            ->get();

        // Рахуємо вартість майбутніх раціонів по кожному клієнту
        $futureValueByClient = [];
        foreach ($futureOrderDays as $od) {
            $order = $od->order;
            if (!$order) continue;
            $dur = max(1, (int) $order->duration);
            $dayValue = max(0,
                (float) $order->total_price / $dur
                - (float) $order->discount_amount / $dur
                - (float) $od->discount_amount
            );
            $futureValueByClient[$order->client_id] = ($futureValueByClient[$order->client_id] ?? 0) + $dayValue;
        }

        // Завантажуємо баланси клієнтів одним запитом
        $clientBalances = Client::whereIn('id', array_keys($futureValueByClient))
            ->pluck('balance', 'id');

        // Розраховуємо передоплачену суму з урахуванням часткових оплат
        $prepaidValue = 0;
        foreach ($futureValueByClient as $clientId => $futureValue) {
            $balance = (float) ($clientBalances[$clientId] ?? 0);
            // balance >= 0 → всі майбутні раціони покриті (є надлишок)
            // balance < 0  → покрита лише частина майбутніх раціонів
            $prepaidValue += max(0, min($futureValue, $futureValue + $balance));
        }
        $prepaidValue = round($prepaidValue);

        // Борг клієнтів — дні вже доставлені але не оплачені
        // Формула: борг = max(0, -balance - future_undelivered)
        // Якщо balance = -700, future = 400 → борг = 300 (3 дні з'їли, не заплатили)
        $debtorClientsCount = 0;
        $totalClientDebt    = 0;

        $debtorClients = Client::where('balance', '<', 0)
            ->select('id', 'balance')
            ->get();

        foreach ($debtorClients as $client) {
            $balance     = (float) $client->balance;
            $futureValue = $futureValueByClient[$client->id] ?? 0;
            $debt        = max(0, -$balance - $futureValue);
            if ($debt > 0.01) {
                $debtorClientsCount++;
                $totalClientDebt += $debt;
            }
        }
        $totalClientDebt = round($totalClientDebt);

        // 8. Повернення у View
        return view('analytics.index', compact(
            'dates', 'startDate', 'endDate',
            'rationsCount', 'totalRations',
            'revenueCount', 'totalRevenue',
            'foodCostCount', 'totalFoodCost',
            'fopCount', 'totalFop',
            'discountCount', 'totalDiscount',  // 🔥 Знижки
            'spoilagePercent', 'otherExpenses', 'activeTab',
            'rentByDay', 'utilitiesByDay', 'monthlyRent', 'monthlyUtilities',
            'unitEconomics', 'marketingStats', 'retentionStats',
            'cashReceivedPeriod', 'cashBalance', 'prepaidValue',
            'totalClientDebt', 'debtorClientsCount',
            'packagingCount', 'totalPackagingCost',
            'deliveryCostByDate', 'totalDeliveryCost',
            'projectStats',
            'individualStats'
        ));
    }

    /**
     * 🔥 МЕТОД ДЛЯ РЕАЛЬНОЇ ВИПЛАТИ ЗАРПЛАТИ
     */
    public function paySalary(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'account_id'  => 'required|exists:accounts,id',
            'amount'      => 'required|numeric|min:1',
        ]);

        // Використовуємо транзакцію, щоб дані не "розірвалися" при помилці
        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $employee = \App\Models\Employee::findOrFail($request->employee_id);
            $account  = \App\Models\Account::findOrFail($request->account_id);
            $amount   = (float) $request->amount;

            // 1. Зменшуємо борг компанії перед співробітником (його баланс)
            $employee->decrement('balance', $amount);

            // 2. Створюємо транзакцію — PaymentObserver автоматично спише гроші з рахунку
            \App\Models\Transaction::create([
                'account_id' => $account->id,
                'amount'     => $amount,
                'type'       => 'expense',
                'category'   => 'Виплата ЗП',
                'comment'    => "Виплата ЗП: {$employee->name}",
                'date'       => now(),
                'user_id'    => auth()->id(),
            ]);
        });

        return back()->with('success', "Успішно виплачено {$request->amount} грн для {$request->employee_name}");
    }
}