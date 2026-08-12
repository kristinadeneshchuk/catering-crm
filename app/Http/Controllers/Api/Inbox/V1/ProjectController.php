<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\CalorieRange;
use App\Models\Project;
use App\Models\Tariff;
use App\Models\TariffPrice;
use Illuminate\Http\JsonResponse;

/**
 * Довідник брендів і каталог доступних тарифів.
 *
 * Кожен Telegram-акаунт прив'язаний до одного бренду, тому менеджер бренд не
 * обирає — Inbox бере його з налаштувань акаунта і питає каталог саме по ньому.
 */
class ProjectController extends Controller
{
    public function index(): JsonResponse
    {
        $projects = Project::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Project $p) => [
                'id'     => $p->id,
                'code'   => $p->slug,
                'name'   => $p->name,
                'active' => (bool) $p->is_active,
            ]);

        return response()->json(['data' => $projects]);
    }

    /**
     * Тарифи бренду з калорійностями, для яких реально є ціна.
     *
     * Комбінації без ціни не віддаємо взагалі: інакше менеджер зміг би обрати
     * варіант, який потім впаде на розрахунку. Тариф з повністю порожньою
     * матрицею цін у каталог не потрапляє.
     */
    public function catalog(Project $project): JsonResponse
    {
        $tariffs = Tariff::where('project', $project->slug)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $ranges = CalorieRange::orderBy('min_kcal')->get()->keyBy('id');

        $prices = TariffPrice::whereIn('tariff_id', $tariffs->pluck('id'))
            ->where('price_per_day', '>', 0)
            ->get()
            ->groupBy('tariff_id');

        $payload = $tariffs
            ->map(function (Tariff $tariff) use ($ranges, $prices) {
                $available = ($prices[$tariff->id] ?? collect())
                    ->filter(fn (TariffPrice $p) => $ranges->has($p->calorie_range_id))
                    ->map(function (TariffPrice $p) use ($ranges) {
                        $range = $ranges[$p->calorie_range_id];

                        return [
                            'id'            => $range->id,
                            'label'         => $range->name,
                            'min_calories'  => (int) $range->min_kcal,
                            'max_calories'  => (int) $range->max_kcal,
                            'price_per_day' => (float) $p->price_per_day,
                        ];
                    })
                    ->sortBy('min_calories')
                    ->values();

                return [
                    'id'             => $tariff->id,
                    'name'           => $tariff->name,
                    'min_days'       => $tariff->min_days,
                    'calorie_ranges' => $available,
                ];
            })
            ->filter(fn (array $t) => $t['calorie_ranges']->isNotEmpty())
            ->values();

        return response()->json([
            'project' => [
                'id'   => $project->id,
                'code' => $project->slug,
                'name' => $project->name,
            ],
            'tariffs' => $payload,
        ]);
    }
}
