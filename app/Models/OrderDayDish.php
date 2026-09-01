<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class OrderDayDish extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['order_id', 'date', 'meal_type_id', 'dish_id', 'weight_grams'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('order_day_dish');
    }

    protected $fillable = [
        'order_id',
        'date',
        'meal_type_id',
        'dish_id',
        'weight_grams',
        'cooking_note',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(MealType::class);
    }
}
