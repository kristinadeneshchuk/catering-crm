<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuPlan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'cycle_days',
        'cycle_start_date',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'cycle_start_date' => 'date',
        'is_default'       => 'boolean',
        'cycle_days'       => 'integer',
        'sort_order'       => 'integer',
    ];

    // === ВІДНОШЕННЯ ===

    public function dailyMenus(): HasMany
    {
        return $this->hasMany(DailyMenu::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class, 'default_menu_plan_id');
    }

    // === ХЕЛПЕРИ ЦИКЛУ ===

    /**
     * Який день циклу припадає на вказану дату для цього плану.
     * Повертає 1..cycle_days.
     */
    public function globalDayFor(string|Carbon $date): int
    {
        $target = $date instanceof Carbon ? $date : Carbon::parse($date);
        $anchor = $this->cycle_start_date instanceof Carbon
            ? $this->cycle_start_date
            : Carbon::parse($this->cycle_start_date);

        $cycleDays = max(1, (int) $this->cycle_days);
        $diff = (int) abs($target->diffInDays($anchor));
        return ($diff % $cycleDays) + 1;
    }

    /**
     * Денне меню цього плану на конкретну дату (з усіма потрібними звʼязками).
     */
    public function menuFor(string|Carbon $date): ?DailyMenu
    {
        return $this->dailyMenus()
            ->where('day_number', $this->globalDayFor($date))
            ->first();
    }

    // === SCOPES ===

    /**
     * Дефолтний план — використовується для замовлень без явного menu_plan_id.
     */
    public static function default(): ?MenuPlan
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('id')->first();
    }
}
