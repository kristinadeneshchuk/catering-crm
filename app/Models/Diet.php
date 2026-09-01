<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Лікувальна дієта (стіл за Певзнером).
 *
 * Дані — чернетка з відкритих джерел, доки технолог не проставить is_reviewed.
 * Саме тому в промт генератора меню йде і review_notes: якщо в дієті щось
 * не узгоджене, модель має поводитись обережніше, а не вигадувати.
 */
class Diet extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_reviewed' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    /** «Стіл №5 — печінка, жовчні шляхи» */
    public function label(): string
    {
        return 'Стіл №' . $this->number . ' — ' . $this->name;
    }

    /**
     * Блок правил для промта генератора індивідуального меню.
     * Порожні поля не додаємо, щоб не годувати модель пустими рядками.
     */
    public function promptRules(): string
    {
        $parts = array_filter([
            'ДІЄТА: ' . $this->label(),
            $this->indications     ? 'Показання: ' . $this->indications : null,
            $this->forbidden       ? 'КАТЕГОРИЧНО ЗАБОРОНЕНО: ' . $this->forbidden : null,
            $this->allowed         ? 'Дозволено: ' . $this->allowed : null,
            $this->cooking_methods ? 'Спосіб приготування: ' . $this->cooking_methods : null,
            $this->temperature_note ? 'Температура подачі: ' . $this->temperature_note : null,
            $this->kitchen_note    ? 'Правила кухні: ' . $this->kitchen_note : null,
        ]);

        return implode("\n", $parts);
    }
}
