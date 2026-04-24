<?php

namespace App\Models;

use App\Services\TelegramService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DishRating extends Model
{
    protected $fillable = [
        'order_id',
        'dish_id',
        'date',
        'stars',
        'comment',
    ];

    protected $casts = [
        'date'  => 'date',
        'stars' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    protected static function booted(): void
    {
        static::created(function (DishRating $rating) {
            $stars     = (int) $rating->stars;
            $starsLine = str_repeat('⭐', $stars) . str_repeat('☆', max(0, 5 - $stars));

            $dishName   = $rating->dish?->name ?? '—';
            $clientName = $rating->order?->client?->name ?? '—';

            $lines = [];
            $lines[] = "📊 <b>Новий рейтинг</b>";
            $lines[] = "";
            $lines[] = "{$starsLine} <b>{$stars}/5</b>";
            $lines[] = "🍽 <b>Страва:</b> {$dishName}";
            $lines[] = "👤 <b>Клієнт:</b> {$clientName}";

            if (!empty($rating->comment)) {
                $lines[] = "💬 <b>Коментар:</b> {$rating->comment}";
            }

            app(TelegramService::class)->sendToOwnerAndManager(implode("\n", $lines));
        });
    }
}
