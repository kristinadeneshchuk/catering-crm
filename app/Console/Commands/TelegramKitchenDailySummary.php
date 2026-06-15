<?php

namespace App\Console\Commands;

use App\Models\DishRating;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TelegramKitchenDailySummary extends Command
{
    protected $signature = 'telegram:kitchen-daily-summary';
    protected $description = 'Підсумок рейтингів страв за день у чат кухарів';

    public function handle(TelegramService $telegram): void
    {
        $today = Carbon::today();

        $ratings = DishRating::whereDate('date', $today)
            ->with('dish')
            ->get();

        if ($ratings->isEmpty()) {
            $telegram->sendToKitchen("📊 <b>Підсумок рейтингів за " . $today->format('d.m.Y') . "</b>\n\nОцінок сьогодні не надходило.");
            return;
        }

        // Групуємо по стравах
        $byDish = $ratings->groupBy('dish_id');

        $lines = [];
        $lines[] = "📊 <b>Підсумок рейтингів за " . $today->format('d.m.Y') . "</b>";
        $lines[] = "Оцінок: <b>{$ratings->count()}</b>";
        $lines[] = "";

        // Сортуємо: спочатку найгірші (щоб кухарі побачили проблеми)
        $dishStats = $byDish->map(function ($group) {
            $avg = round($group->avg('stars'), 1);
            return [
                'name'  => $group->first()->dish?->name ?? '—',
                'avg'   => $avg,
                'count' => $group->count(),
                'stars' => $group->pluck('stars')->toArray(),
            ];
        })->sortBy('avg')->values();

        foreach ($dishStats as $stat) {
            $avgStars = $stat['avg'];
            $starsLine = str_repeat('⭐', (int) round($avgStars)) . str_repeat('☆', max(0, 5 - (int) round($avgStars)));
            $emoji = $avgStars >= 4.5 ? '🟢' : ($avgStars >= 3.5 ? '🟡' : '🔴');

            $lines[] = "{$emoji} <b>{$stat['name']}</b>";
            $lines[] = "   {$starsLine} <b>{$avgStars}/5</b> ({$stat['count']} оцінок)";
        }

        // Коментарі за день
        $comments = $ratings->whereNotNull('comment')->filter(fn($r) => !empty(trim($r->comment ?? '')));
        if ($comments->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "💬 <b>Коментарі:</b>";
            foreach ($comments->take(10) as $r) {
                $dishName = $r->dish?->name ?? '—';
                $stars = $r->stars;
                $lines[] = "• {$dishName} ({$stars}⭐): {$r->comment}";
            }
        }

        $telegram->sendToKitchen(implode("\n", $lines));
        $this->info('Kitchen daily summary sent.');
    }
}
