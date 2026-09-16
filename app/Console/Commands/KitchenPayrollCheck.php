<?php

namespace App\Console\Commands;

use App\Services\Ai\KitchenAttendance;
use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Чи не забагато людей на кухні сьогодні.
 *
 * Поріг власника: фонд оплати кухні не більший за 130 ₴ на порцію. Сигнал
 * іде лише при перевищенні — щоб повідомлення не стало щоденним шумом.
 */
class KitchenPayrollCheck extends Command
{
    protected $signature = 'kitchen:payroll-check {--date=} {--dry : Показати в консолі, нічого не відправляючи}';

    protected $description = 'Порівняти фонд оплати кухні з порогом на порцію і попередити власника';

    public function handle(KitchenAttendance $attendance, TelegramService $telegram): int
    {
        $date  = $this->option('date') ? \Carbon\Carbon::parse($this->option('date')) : now();
        $limit = (float) config('ops.kitchen_fot_per_portion', 130);
        $data  = $attendance->payrollOfDay($date);

        if ($data['portions'] === 0 || $data['per_portion'] <= $limit) {
            $this->info("ФОТ {$data['fot']} ₴ · порцій {$data['portions']} · {$data['per_portion']} ₴/порція — у нормі.");

            return self::SUCCESS;
        }

        $people = $data['people']
            ->map(fn ($shift) => '• '.($shift->employee?->name ?? '—')
                .' · '.($shift->position_key ?: '—')
                .' · '.number_format((float) $shift->rate, 0, ',', ' ').' ₴')
            ->implode("\n");

        $text = "⚠️ <b>ФОТ кухні вище норми</b> · ".$date->format('d.m')."\n"
            .number_format($data['fot'], 0, ',', ' ').' ₴ на '.$data['portions'].' порцій = <b>'
            .number_format($data['per_portion'], 0, ',', ' ')." ₴/порція</b> (норма до ".number_format($limit, 0, ',', ' ').")\n"
            ."Людей на зміні: ".$data['people']->count()."\n".$people;

        if ($this->option('dry')) {
            $this->line(strip_tags($text));

            return self::SUCCESS;
        }

        $telegram->sendToOwner($text);
        $this->warn('Надіслано власнику.');

        return self::SUCCESS;
    }
}
