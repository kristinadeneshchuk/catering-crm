<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Project;

class Packaging extends Model
{
    protected $fillable = [
        'name', 'unit', 'stock', 'price', 'project',
        'packaging_type', 'capacity', 'capacity_unit', 'pair_id',
    ];

    // Типи упаковки
    const TYPES = [
        'бокс'     => 'Бокс (салатник)',
        'кришка'   => 'Кришка для боксу',
        'супник'   => 'Супник (контейнер для супу)',
        'кришка-супник' => 'Кришка для супника',
        'пляшка'   => 'Пляшка / Стакан',
        'ковпачок' => 'Ковпачок для пляшки',
        'стакан-десерт'  => 'Стакан для десерту',
        'кришка-десерт'  => 'Кришка для стакану десерту',
        'пакет'    => 'Пакет (крафт)',
        'наклейка' => 'Наклейка',
        'прибори'  => 'Прибори (виделка/ніж/саше)',
        'серветка' => 'Серветка',
    ];

    // Типи контейнерів що вибираються в страві
    const DISH_TYPES = [
        'бокс'          => 'Бокс (салатник)',
        'супник'        => 'Супник (контейнер для супу)',
        'пляшка'        => 'Пляшка / Стакан',
        'стакан-десерт' => 'Стакан для десерту',
    ];

    public function projectData(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class, 'project', 'slug');
    }

    // Прив'язана пара (наприклад кришка до боксу)
    public function pair(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Packaging::class, 'pair_id');
    }

    // Елементи що посилаються на цей як пару
    public function pairedWith(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Packaging::class, 'pair_id');
    }

    // Чи є це пакувальний матеріал для їжі (не госптовар)
    public function isFoodPackaging(): bool
    {
        return !is_null($this->packaging_type);
    }

    // Повна назва ємності: "550 мл" або "300 г"
    public function getCapacityLabelAttribute(): ?string
    {
        if (!$this->capacity) return null;
        return $this->capacity . ' ' . ($this->capacity_unit ?? 'мл');
    }
}
