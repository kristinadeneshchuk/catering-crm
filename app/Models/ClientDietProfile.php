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
