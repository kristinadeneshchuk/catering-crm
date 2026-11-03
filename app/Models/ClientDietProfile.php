<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Профіль харчування індивідуального клієнта: режим, групи «не можна», «краще ні»,
 * «більше», правила-частоти, улюблені страви, пам'ятка кухні.
 */
class ClientDietProfile extends Model
{
    public const MODES = [
        'cycle_replacements' => 'Цикл + заміни',
        'hybrid'             => 'Гібрид (частина прийомів — власні страви)',
        'own_menu_only'      => 'Лише власне меню',
        'vip'                => 'VIP — лише його меню, цикл не адаптувати',
    ];

    /** Типи правил у rules[]: усе, крім text, читають MenuGuard (V9) і агент «Меню». */
    public const RULE_KINDS = [
        'max_per_day'     => 'Не більше N на день (група)',
        'max_per_week'    => 'Не більше N за меню (група)',
        'min_gap_days'    => 'Не частіше ніж раз на N днів (група або страва)',
        'meal_no_group'   => 'Прийом без групи (напр. вечеря без вуглеводів)',
        'meal_only_group' => 'Прийом лише з групи (напр. перекус — творожне)',
        'serve'           => 'Подача (кухня)',
        'text'            => 'Текстом (код не перевіряє)',
    ];

    /** Коди подачі для kind=serve — друкуються в памʼятці кухні. */
    public const SERVE_CODES = [
        'separate_containers' => 'Кожен прийом окремим контейнером, нічого не змішувати',
        'no_rolls'            => 'Не змотувати (тако, лаваш, тортилью подавати розкладеними)',
        'sauce_apart'         => 'Соус окремо',
        'hot_only'            => 'Лише гаряче: без салатів і холодних страв',
        'no_salads'           => 'Без салатів',
        'protein_weighed'     => 'Мʼясо/рибу зважити, вага на контейнері',
    ];

    protected $guarded = [];

    protected $casts = [
        'excluded_groups'   => 'array',
        'avoid'             => 'array',
        'want_more'         => 'array',
        'rules'             => 'array',
        'favorite_dish_ids' => 'array',
    ];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}
