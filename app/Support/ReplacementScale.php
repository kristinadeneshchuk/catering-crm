<?php

namespace App\Support;

/**
 * Масштаб техкарти для страви-заміни.
 *
 * Масштаб порції рахують від базової ваги СТАНДАРТНОЇ страви (вага порції ÷ її
 * база). Заміна має вийти тієї самої ваги, тож її техкарту треба множити на
 * вагу порції ÷ базу ЗАМІНИ. Коли бази різні (циклова страва — техкарта на
 * ~1000 г, «Інд ·» — на одну порцію 300–500 г), старий масштаб давав кухні
 * втричі меншу закладку, ніж порція, яку фасують.
 */
class ReplacementScale
{
    public static function for($originalDish, $replacementDish, float $scale): float
    {
        $from = (float) ($originalDish->base_weight_g ?? 0);
        $to   = (float) ($replacementDish->base_weight_g ?? 0);

        return ($from > 0 && $to > 0) ? $scale * $from / $to : $scale;
    }
}
