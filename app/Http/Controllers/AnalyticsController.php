<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\OrderDay;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        // 1. Беремо дати з інпутів
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Захист від занадто великих діапазонів
        if ($start->diffInDays($end) > 60) {
            $end = $start->copy()->addDays(60);
            $endDate = $end->format('Y-m-d');
        }

        // 2. Формуємо масив дат
        $dates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dates[$date->format('Y-m-d')] = $date->format('d.m');
        }

        // 3. Отримуємо всі записи OrderDay за період
        $validDays = OrderDay::whereBetween('date', [$startDate, $endDate])
            ->with('order') 
            ->get();

        $groupedDays = $validDays->groupBy(function ($item) {
            return Carbon::parse($item->date)->format('Y-m-d');
        });

        // Масиви для результатів
        $rationsCount = [];
        $totalRations = 0;
        
        $revenueCount = [];
        $totalRevenue = 0;

        foreach ($dates as $ymd => $dm) {
            $days = $groupedDays->get($ymd, collect());
            
            // КІЛЬКІСТЬ РАЦІОНІВ
            $count = $days->count();
            $rationsCount[$ymd] = $count;
            $totalRations += $count;

            // ВИРУЧКА (Метод нарахування: total_price / duration)
            $dailyRevenue = 0;
            foreach ($days as $orderDay) {
                $order = $orderDay->order;
                if ($order) {
                    $duration = max(1, (int)$order->duration); 
                    $pricePerDay = (float)$order->total_price / $duration;
                    $dailyRevenue += $pricePerDay;
                }
            }
            
            $revenueCount[$ymd] = round($dailyRevenue);
            $totalRevenue += round($dailyRevenue);
        }

        return view('analytics.index', compact(
            'dates', 
            'startDate', 
            'endDate',
            'rationsCount',
            'totalRations',
            'revenueCount',
            'totalRevenue'
        ));
    }
}