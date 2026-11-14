<?php

namespace App\Console\Commands;

use App\Services\Kitchen\IndividualMenuGaps;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class IndividualMenuGapsNotify extends Command
{
    protected $signature = 'orders:individual-menu-gaps {--days=3 : на скільки днів уперед дивитись} {--dry : лише показати, не надсилати}';

    protected $description = 'Попередження власнику й менеджеру: індивідуальні замовлення без прописаного меню на найближчі дні';

    public function handle(IndividualMenuGaps $gaps, TelegramService $telegram): int
    {
        // Раціон на завтра готують сьогодні, вечірню доставку на післязавтра — завтра,
        // тому «сьогодні» теж має сенс лише до ранку; дивимось від завтра.
        $from = now()->addDay()->startOfDay();
        $to   = now()->addDays(max(1, (int) $this->option('days')))->startOfDay();

        $text = $gaps->report($from, $to);
        if ($text === null) {
            $this->info('Прогалин немає.');

            return self::SUCCESS;
        }

        $this->line(strip_tags($text));
        if (! $this->option('dry')) {
            $telegram->sendToOwnerAndManager($text);
        }

        return self::SUCCESS;
    }
}
