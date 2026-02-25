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

        // Обмеження діапазону (щоб не покласти сервер, якщо випадково вибрати 10 років)
        if ($start->diffInDays($end) > 60) {
            $end = $start->copy()->addDays(60);
            $endDate = $end->format('Y-m-d');
        }

        // 2. Формуємо масив дат (Ключ = Y-m-d, Значення = d.m)
        $dates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dates[$date->format('Y-m-d')] = $date->format('d.m');
        }

        // ==========================================
        // 🔥 ЛОГІКА 1: Кількість доставлених раціонів
        // ==========================================
        
        // ПРИБРАЛИ ФІЛЬТР ЗА СТАТУСОМ!
        // Якщо день є в базі OrderDay — значить ми його готували і доставляли, рахуємо його 100%.
        $validDays = OrderDay::whereBetween('date', [$startDate, $endDate])
            ->with('order') // Одразу вантажимо замовлення, це знадобиться нам далі для розрахунку грошей
            ->get();

        // Групуємо їх по датах і рахуємо кількість
        $groupedDays = $validDays->groupBy(function ($item) {
            return Carbon::parse($item->date)->format('Y-m-d');
        })->map->count();

        // Заповнюємо фінальний масив (навіть якщо в якийсь день 0 раціонів)
        $rationsCount = [];
        $totalRations = 0;

        foreach ($dates as $ymd => $dm) {
            $count = $groupedDays->get($ymd, 0); // Якщо на цей день немає записів - ставимо 0
            $rationsCount[$ymd] = $count;
            $totalRations += $count;
        }

        return view('analytics.index', compact(
            'dates', 
            'startDate', 
            'endDate',
            'rationsCount', // Передаємо масив кількості по днях
            'totalRations'  // Передаємо загальну суму
        ));
    }
}