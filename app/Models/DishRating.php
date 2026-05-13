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
            $order      = $rating->order;
            $client     = $order?->client;
            $clientName = $client?->name ?? '—';
            $phone      = $client?->phone;
            $tg         = $client?->telegram_username;

            // Проєкт (план меню) або «Індивідуальний»
            if (($order?->menu_type ?? null) === 'individual') {
                $planLabel = 'Індивідуальний';
            } else {
                $planLabel = $order?->menuPlan?->name ?: '—';
            }

            $lines = [];
            $lines[] = "📊 <b>Новий рейтинг</b>";
            $lines[] = "";
            $lines[] = "{$starsLine} <b>{$stars}/5</b>";
            $lines[] = "🍽 <b>Страва:</b> {$dishName}";
            $lines[] = "📋 <b>Проєкт:</b> {$planLabel}";
            $lines[] = "👤 <b>Клієнт:</b> {$clientName}";

            if (!empty($phone)) {
                $lines[] = "📞 <b>Телефон:</b> {$phone}";
            }

            if (!empty($tg)) {
                $tgHandle = ltrim($tg, '@');
                $lines[] = "✈️ <b>Telegram:</b> <a href=\"https://t.me/{$tgHandle}\">@{$tgHandle}</a>";
            }

            if (!empty($rating->comment)) {
                $lines[] = "💬 <b>Коментар:</b> {$rating->comment}";
            }

            app(TelegramService::class)->sendToOwnerManagerCook(implode("\n", $lines));
        });
    }
}
