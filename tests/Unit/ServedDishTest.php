<?php

namespace Tests\Unit;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderReplacement;
use App\Traits\CalculatesOrderPlan;
use Tests\TestCase;

/**
 * Страва-заміна з шаблону клієнта рахується за власною калорійністю;
 * ручна заміна менеджера — у вазі стандартної страви, як і раніше.
 */
class ServedDishTest extends TestCase
{
    /** Страва з готовими підсумками техкарти — без складу й звернень до складу. */
    protected function dish(int $id, float $prot, float $fat, float $carb): Dish
    {
        $dish = new class extends Dish {
            public array $totals = [];

            public function getCalculatedTotalsAttribute(): array
            {
                return $this->totals;
            }
        };
        $dish->forceFill(['id' => $id, 'name' => "d{$id}", 'base_weight_g' => 100]);
        $dish->totals = ['kcal' => $prot * 4 + $fat * 9 + $carb * 4, 'prot' => $prot, 'fat' => $fat, 'carb' => $carb, 'cost' => 0, 'output_weight' => 100];

        return $dish;
    }

    protected function served(?string $comment, bool $forceApproved = false, ?Dish $replacement = null): Dish
    {
        $dessert = $this->dish(1, 6, 18, 40);             // 346 ккал/100 г
        $replacement ??= $this->dish(2, 16, 2, 10);       // 122 ккал/100 г

        $order = new Order();
        $reps = collect();
        if ($comment !== null) {
            $rep = new OrderReplacement();
            $rep->forceFill(['dish_id' => 1, 'original_product_id' => null, 'replacement_dish_id' => $replacement->id, 'force_approved' => $forceApproved, 'comment' => $comment]);
            $rep->setRelation('replacementDish', $replacement);
            $reps->push($rep);
        }
        $order->setRelation('replacements', $reps);

        $calc = new class { use CalculatesOrderPlan; };

        return (fn () => $this->servedDish($order, $dessert))->call($calc);
    }

    public function test_a_bundle_swap_is_weighed_by_its_own_calories(): void
    {
        $this->assertSame(2, $this->served('Пакет: Спорт Про')->id);
    }

    public function test_a_manual_swap_keeps_the_standard_weight(): void
    {
        $this->assertSame(1, $this->served('не любить солодке')->id);
        $this->assertSame(1, $this->served(null)->id);
    }

    public function test_force_approved_and_empty_tech_cards_fall_back_to_the_menu_dish(): void
    {
        $this->assertSame(1, $this->served('Пакет: Спорт Про', true)->id);
        $this->assertSame(1, $this->served('Пакет: Спорт Про', false, $this->dish(3, 0, 0, 0))->id);
    }
}
