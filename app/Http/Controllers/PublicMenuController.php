<?php

namespace App\Http\Controllers;

use App\Models\DailyMenu;
use App\Models\MenuPlan;
use App\Support\PublicMenuBuilder;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Публічне «Меню на сьогодні» — постійне посилання, яке менеджери надсилають
 * клієнтам під час консультації. Без авторизації, без токена.
 *
 * Показує меню на 3 дні (сьогодні / завтра / післязавтра) з граммовкою та КБЖУ
 * під обраний калораж. Калораж перемикається (900…3400), дефолт 1800.
 */
class PublicMenuController extends Controller
{
    /** Стандартні калоражі, під які рахуємо граммовку. */
    public const TIERS = [900, 1100, 1300, 1600, 1800, 2000, 2400, 3000, 3400];

    private const DEFAULT_TIER = 1800;

    public function today(Request $request)
    {
        $kcal = (int) $request->integer('kcal');
        if (! in_array($kcal, self::TIERS, true)) {
            $kcal = self::DEFAULT_TIER;
        }

        $plan = MenuPlan::default();

        $days = [];
        if ($plan) {
            foreach ([0, 1, 2] as $offset) {
                $date  = Carbon::now()->startOfDay()->addDays($offset);
                $ymd   = $date->format('Y-m-d');
                $menu  = DailyMenu::where('menu_plan_id', $plan->id)
                    ->where('day_number', $plan->globalDayFor($date))
                    ->with([
                        'menuItems.mealType',
                        'menuItems.dish.dishIngredients.ingredient',
                        'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                    ])
                    ->first();

                $built = $menu
                    ? PublicMenuBuilder::build($menu, $kcal, $ymd)
                    : ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];

                $days[] = [
                    'date'   => $date,
                    'label'  => $this->dayLabel($offset),
                    'items'  => $built['items'],
                    'totals' => $built['totals'],
                ];
            }
        }

        return view('menu.today', [
            'days'  => $days,
            'kcal'  => $kcal,
            'tiers' => self::TIERS,
        ]);
    }

    private function dayLabel(int $offset): string
    {
        return match ($offset) {
            0 => 'Сьогодні',
            1 => 'Завтра',
            2 => 'Післязавтра',
            default => '',
        };
    }
}
