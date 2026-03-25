<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\OrderDay;
use App\Models\Setting;
use App\Models\DailyMenu;
use App\Models\Ingredient;
use App\Models\Client;
use App\Models\EmployeeShift; // 🔥 Для ФОП
use App\Services\FoodCostService;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    protected $foodCostService;

    public function __construct(FoodCostService $foodCostService)
    {
        $this->foodCostService = $foodCostService;
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

        // 2. Завантаження даних
        // Включаємо всі необхідні зв'язки для FoodCostService та аналітики
        $validDays = OrderDay::whereBetween('date', [$startDate, $endDate])
            ->with([
                'order.client.mealTypes',
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

        // Ініціалізація підсумкових змінних
        $rationsCount = []; $totalRations = 0;
        $revenueCount = []; $totalRevenue = 0;
        $foodCostCount = []; $totalFoodCost = 0;
        $fopCount = []; $totalFop = 0;
        $discountCount = []; $totalDiscount = 0;  // 🔥 Трекінг знижок

        $unitEconomics = [];
        $marketingStats = [];
        $uniqueClientIds = [];

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
            $dailyDiscount = 0;  // 🔥

            if ($count > 0) {
                $diff = abs(Carbon::parse($ymd)->diffInDays($anchorDate));
                $dayNum = ($diff % $cycleDays) + 1;
                $menu = $allMenus->get($dayNum);

                foreach ($days as $orderDay) {
                    $order = $orderDay->order;
                    if (!$order || !$order->client) continue;

                    $uniqueClientIds[$order->client->id] = true;

                    $duration = max(1, (int) $order->duration);

                    // 🔥 Виручка по final_price (net)
                    // Базова ціна дня мінус частка знижки замовлення мінус знижка цього дня
                    $basePricePerDay     = (float) $order->total_price / $duration;
                    $orderDiscountPerDay = (float) $order->discount_amount / $duration;
                    $dayDiscount         = (float) $orderDay->discount_amount;
                    $netPricePerDay      = max(0, $basePricePerDay - $orderDiscountPerDay - $dayDiscount);

                    $dailyRevenue  += $netPricePerDay;
                    $dailyDiscount += $orderDiscountPerDay + $dayDiscount;  // 🔥

                    // Food Cost
                    $orderCost = 0;
                    if ($menu) {
                        $orderCost = $this->foodCostService->calculateOrderFoodCost($order, $menu, $allIngredients);
                        $dailyFoodCost += $orderCost;
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
                }
            }

            $revenueCount[$ymd]  = round($dailyRevenue);
            $totalRevenue        += round($dailyRevenue);
            $foodCostCount[$ymd] = round($dailyFoodCost);
            $totalFoodCost       += round($dailyFoodCost);
            $discountCount[$ymd] = round($dailyDiscount);  // 🔥
            $totalDiscount       += round($dailyDiscount); // 🔥
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
            'segments' => [
                'trial' => ['count' => 0, 'label' => 'Пробні (1-3 дні)', 'color' => 'bg-rose-500'],
                'regular' => ['count' => 0, 'label' => 'Постійні (4-14 днів)', 'color' => 'bg-blue-500'],
                'vip' => ['count' => 0, 'label' => 'VIP (15+ днів)', 'color' => 'bg-avocado-500'],
            ]
        ];

        if (!empty($uniqueClientIds)) {
            $clients = Client::whereIn('id', array_keys($uniqueClientIds))->with('orders')->get();
            $today = Carbon::now()->format('Y-m-d');
            $totalLifetimeDays = 0;
            $totalLtv = 0;

            foreach ($clients as $client) {
                $clientDays = 0; $clientRevenue = 0; $lastOrderEndDate = null;

                foreach ($client->orders as $o) {
                    if ($o->total_price > 0 && !in_array($o->status, ['cancelled'])) {
                        $clientDays += max(1, (int) $o->duration);
                        // 🔥 LTV рахуємо по final_price (реально сплачена сума)
                        $clientRevenue += (float) ($o->final_price > 0 ? $o->final_price : $o->total_price);
                        if (!$lastOrderEndDate || $o->end_date > $lastOrderEndDate) {
                            $lastOrderEndDate = $o->end_date;
                        }
                    }
                }

                $totalLifetimeDays += $clientDays;
                $totalLtv += $clientRevenue;
                $retentionStats['total_clients']++;

                if ($lastOrderEndDate && $lastOrderEndDate >= $today) {
                    $retentionStats['active_now']++;
                } else {
                    $retentionStats['churned']++;
                }

                if ($clientDays <= 3) {
                    $retentionStats['segments']['trial']['count']++;
                } elseif ($clientDays <= 14) {
                    $retentionStats['segments']['regular']['count']++;
                } else {
                    $retentionStats['segments']['vip']['count']++;
                }
            }

            if ($retentionStats['total_clients'] > 0) {
                $retentionStats['avg_lifetime_days'] = $totalLifetimeDays / $retentionStats['total_clients'];
                $retentionStats['avg_ltv'] = $totalLtv / $retentionStats['total_clients'];
                $retentionStats['churn_rate'] = ($retentionStats['churned'] / $retentionStats['total_clients']) * 100;
            }
        }

        // 7. Повернення у View
        return view('analytics.index', compact(
            'dates', 'startDate', 'endDate',
            'rationsCount', 'totalRations',
            'revenueCount', 'totalRevenue',
            'foodCostCount', 'totalFoodCost',
            'fopCount', 'totalFop',
            'discountCount', 'totalDiscount',  // 🔥 Знижки
            'spoilagePercent', 'otherExpenses', 'activeTab',
            'unitEconomics', 'marketingStats', 'retentionStats'
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