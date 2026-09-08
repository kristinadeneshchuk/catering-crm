<?php

namespace App\Http\Controllers\Api\Lunch;

use App\Http\Controllers\Controller;
use App\Models\Dish;
use App\Models\Ingredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Міст до Lunch Hub — сервісу корпоративних обідів.
 *
 * Lunch Hub тримає власні техкартки (дешева лінійка сендвічів, салатів і супів),
 * але рахує їх з наших закупівельних цін: подорожчав продукт — піднялась
 * собівартість обіду. Тому звідси він тягне каталог страв і довідник
 * інгредієнтів, а назад віддає зведення «скільки чого готувати».
 *
 * Тільки читання. У каталог не пишемо, моделі не міняємо, таблиць не заводимо —
 * інтеграція не має залежати від внутрішньої схеми CRM.
 */
class LunchApiController extends Controller
{
    /**
     * Каталог готових страв із собівартістю з техкартки.
     *
     * Собівартість і КБЖУ беремо з Dish::$calculated_totals — тією самою
     * логікою, що рахує CRM. Формули тут не дублюємо: інакше через півроку
     * обіди рахувались би за старим правилом, і ніхто б не помітив.
     */
    public function dishes(): JsonResponse
    {
        // Середні ціни одним запитом. Без цього кожен інгредієнт кожної страви
        // йшов би у stock_document_items окремо — тисячі запитів на 200 страв.
        Ingredient::preloadAveragePrices();

        $dishes = Dish::query()
            ->where('is_semi_finished', false)
            ->with([
                // Напівфабрикати розкриваються рекурсивно, тож тягнемо три рівні
                // вкладеності наперед — глибше техкартки не йдуть.
                'dishIngredients.ingredient',
                'dishIngredients.childDish.dishIngredients.ingredient',
                'dishIngredients.childDish.dishIngredients.childDish.dishIngredients.ingredient',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Dish $dish) => [
                'id'              => $dish->id,
                'name'            => $dish->name,
                'group'           => $dish->group,
                'description'     => $dish->description,
                'photo_url'       => $dish->photo ? Storage::disk('public')->url($dish->photo) : null,

                // Вихід техкартки, а не порція. Картки зроблені приблизно на
                // 1000 г, тож Lunch Hub рахує вартість за 100 г і множить на
                // свою порцію. Семантику цього поля не міняти без узгодження.
                'output_weight_g' => $dish->output_weight,
                'cost'            => $dish->total_cost,
                'kcal'            => $dish->total_kcal,
                'protein_g'       => $dish->total_prot,
                'fat_g'           => $dish->total_fat,
                'carb_g'          => $dish->total_carb,
            ]);

        return response()->json(['data' => $dishes]);
    }

    /**
     * Довідник інгредієнтів із закупівельними цінами.
     *
     * Ціна — та сама, якою CRM рахує собівартість страв: середньозважена з
     * прибуткових накладних, а якщо надходжень не було — ручна price_per_kg.
     * Віддавати саму лише ручну ціну не можна: вона не рухається з ринком, і
     * обіди рахувались би за торішніми цінами.
     */
    public function ingredients(): JsonResponse
    {
        Ingredient::preloadAveragePrices();

        $ingredients = Ingredient::query()
            ->orderBy('name')
            ->get()
            ->map(function (Ingredient $ing) {
                $effective = (float) $ing->average_price;

                return [
                    'id'           => $ing->id,
                    'name'         => $ing->name,
                    'price_per_kg' => round($effective > 0 ? $effective : (float) $ing->price_per_kg, 2),

                    // Одиниця виміру — не косметика. У 20 позицій це «шт», і
                    // тоді ціна вище означає «за штуку», а не за кілограм.
                    // Рахувати їх як кілограми — помилка в рази.
                    'unit'         => $ing->unit,

                    // Відсоток виходу після зачистки: 1 кг закупленого дає
                    // менше кілограма в тарілці, і собівартість це враховує.
                    'yield_percent' => (float) ($ing->yield_percent ?: 100),

                    'kcal_100g'    => (float) $ing->calories_100g,
                    'protein_100g' => (float) $ing->proteins_100g,
                    'fat_100g'     => (float) $ing->fats_100g,
                    'carb_100g'    => (float) $ing->carbs_100g,
                ];
            });

        return response()->json(['data' => $ingredients]);
    }

    /**
     * Прийом зведення «скільки чого готувати».
     *
     * Кладемо у файл, а не в таблицю: інтеграція не має залежати від внутрішньої
     * схеми CRM, а кухня цим ще не користується. Коли знадобиться показати це в
     * Filament — то вже окрема задача, дані до того моменту накопичаться.
     *
     * Повторний запит на ту саму дату перезаписує файл: логіст перебудовує план
     * і надсилає ще раз, і два зведення на один день кухні тільки заважали б.
     */
    public function kitchenPlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'            => ['required', 'date'],
            'lines'           => ['required', 'array', 'min:1'],
            'lines.*.dish_id' => ['nullable', 'integer'],
            'lines.*.name'    => ['nullable', 'string', 'max:255'],
            'lines.*.qty'     => ['required', 'numeric', 'min:0'],
        ]);

        $date = \Carbon\Carbon::parse($data['date'])->format('Y-m-d');

        $payload = [
            'date'          => $date,
            'received_at'   => now()->toIso8601String(),
            'lines'         => $data['lines'],
            'total_qty'     => array_sum(array_column($data['lines'], 'qty')),
        ];

        Storage::disk('lunch')->put(
            "kitchen-plan-{$date}.json",
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        Log::info('[Lunch] Kitchen plan received', [
            'date'      => $date,
            'lines'     => count($data['lines']),
            'total_qty' => $payload['total_qty'],
        ]);

        return response()->json([
            'ok'    => true,
            'lines' => count($data['lines']),
        ]);
    }
}
