<?php

namespace App\Http\Controllers;

use App\Models\CalorieRange;
use App\Models\DailyMenu;
use App\Models\MealPlan;
use App\Models\MenuPlan;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MenuPreviewController extends Controller
{
    public function show(Request $request)
    {
        $plan = MenuPlan::with('dailyMenus.menuItems.dish', 'dailyMenus.menuItems.mealType')
            ->where('is_default', true)
            ->first()
            ?? MenuPlan::with('dailyMenus.menuItems.dish', 'dailyMenus.menuItems.mealType')
                ->orderBy('id')->first();

        $calorieOptions = CalorieRange::orderBy('min_kcal')->get()->map(fn ($r) => [
            'value' => (int) $r->min_kcal,
            'label' => $r->name,
        ])->values();

        $defaultKcal = (int) ($calorieOptions->firstWhere('value', '>=', 1800)['value'] ?? $calorieOptions->first()['value'] ?? 1800);
        $kcal = (int) $request->input('kcal', $defaultKcal);

        $mealPlanInfo = MealPlan::with('mealTypes')->orderBy('min_kcal')->get()->map(fn ($p) => [
            'range_label'   => $p->min_kcal === $p->max_kcal
                ? "{$p->min_kcal} ккал"
                : "{$p->min_kcal}–{$p->max_kcal} ккал",
            'meal_count'    => $p->mealTypes->count(),
            'meal_names'    => $p->mealTypes->pluck('name')->all(),
        ])->values();

        $days = [];
        if ($plan) {
            $today = now()->startOfDay();
            for ($i = 0; $i < 3; $i++) {
                $date = $today->copy()->addDays($i);
                $menu = $plan->menuFor($date);
                $days[] = [
                    'date'    => $date,
                    'weekday' => $this->weekdayUk($date),
                    'items'   => $menu ? $this->buildPreviewDay($menu, $kcal) : [],
                ];
            }
        }

        return view('menu.preview', [
            'kcal'           => $kcal,
            'calorieOptions' => $calorieOptions,
            'mealPlanInfo'   => $mealPlanInfo,
            'days'           => $days,
        ]);
    }

    private function buildPreviewDay(DailyMenu $menu, int $kcal): array
    {
        if ($kcal <= 0) return [];

        $allowedSort = MealPlan::getAllowedSortOrders($kcal);
        $weightMult  = \App\Support\DailyWeightMultiplier::for(now()->toDateString());

        $items = $menu->menuItems
            ->filter(fn ($i) => $i->dish && in_array($i->mealType?->sort_order, $allowedSort, true))
            ->sortBy(fn ($i) => $i->mealType?->sort_order ?? 99)
            ->values();

        if ($items->isEmpty()) return [];

        $byMeal = $items->groupBy('meal_type_id');

        $rawPct = [];
        foreach ($byMeal as $mealTypeId => $arr) {
            $fi = $arr->first();
            $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                ? (float) $fi->custom_energy_percent
                : (float) ($fi->mealType?->energy_percent ?? 0);
        }
        $totalPct   = array_sum($rawPct);
        $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

        $result = [];
        foreach ($byMeal as $mealTypeId => $arr) {
            $first    = $arr->first();
            $mealName = $first->mealType?->name ?? '-';
            $mealSort = $first->mealType?->sort_order ?? 99;

            $p = ($rawPct[$mealTypeId] ?? 0) * $normFactor;
            $mealKcal = $p > 0
                ? $kcal * ($p / 100.0)
                : $kcal * (1.0 / max(1, $byMeal->count()));
            $kcalPerDish = $mealKcal / max(1, $arr->count());

            foreach ($arr as $mi) {
                $d = $mi->dish;
                if (! $d) continue;

                $baseW     = (float) ($d->base_weight_g ?? 0);
                $totalKcal = (float) ($d->total_kcal ?? 0);
                $kcal100   = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;
                $weight    = $kcal100 > 0 ? (int) round(($kcalPerDish / $kcal100) * 100.0 * $weightMult) : 0;
                $dishKcal  = $weight * $kcal100 / 100.0;

                $result[] = [
                    'meal'      => $mealName,
                    'meal_sort' => $mealSort,
                    'dish_name' => $d->name,
                    'weight'    => $weight,
                    'kcal'      => (int) round($dishKcal),
                ];
            }
        }

        usort($result, fn ($a, $b) => $a['meal_sort'] <=> $b['meal_sort']);
        return $result;
    }

    private function weekdayUk(Carbon $date): string
    {
        return [
            'Mon' => 'Пн',
            'Tue' => 'Вт',
            'Wed' => 'Ср',
            'Thu' => 'Чт',
            'Fri' => 'Пт',
            'Sat' => 'Сб',
            'Sun' => 'Нд',
        ][$date->format('D')] ?? $date->format('D');
    }
}
