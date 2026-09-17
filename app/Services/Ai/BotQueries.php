<?php

namespace App\Services\Ai;

use App\Filament\Resources\StockDocumentResource;
use App\Models\AiRun;
use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;

/**
 * Короткі команди боту: подивитись чернетки, залишок, ціни, витрати на ШІ.
 * Усе лише читає — жодна команда нічого не змінює.
 */
class BotQueries
{
    public function __construct(private PriceWatcher $prices)
    {
    }

    /** @return string|null текст відповіді; null — це не команда */
    public function handle(string $text): ?string
    {
        $text = trim($text);

        if (! str_starts_with($text, '/')) {
            return null;
        }

        [$command, $argument] = array_pad(preg_split('/\s+/u', $text, 2), 2, '');
        $command = mb_strtolower(preg_replace('/@\S+$/', '', $command));

        // «/+» — відмітка кухні, а не довідкова команда.
        if (in_array($command, ['/+', '/плюс'], true)) {
            return null;
        }

        // /start з кодом — це підключення курʼєра, не довідка.
        if ($command === '/start' && trim($argument) !== '') {
            return null;
        }

        return match ($command) {
            '/допомога', '/help', '/start' => $this->help(),
            '/чернетки', '/drafts'         => $this->drafts(),
            '/склад', '/stock'             => $this->stock($argument),
            '/ціни', '/цены', '/prices'    => $this->priceHistory($argument),
            '/витрати', '/costs'           => $this->costs(),
            '/стан', '/status'             => $this->status(),
            '/ші', '/ai'                   => $this->switchAi($argument),
            default                        => null,
        };
    }

    public function help(): string
    {
        return "Що я вмію:\n"
            ."• <b>надішліть фото накладної</b> — зроблю чернетку надходження, спитаю постачальника й фасування\n"
            ."• <b>/чернетки</b> — незакриті чернетки накладних\n"
            ."• <b>/склад курка</b> — залишок і середня ціна товару\n"
            ."• <b>/ціни курка</b> — історія закупівель і як змінювалась ціна\n"
            ."• <b>/витрати</b> — скільки коштує ШІ сьогодні й цього місяця\n"
            ."• <b>/стан</b> — що ШІ зробив сьогодні, черга, помилки\n"
            ."• <b>/ші стоп</b> і <b>/ші пуск</b> — вимкнути або ввімкнути ШІ\n"
            .'• <b>/id</b> — ID чату (для налаштувань)';
    }

    public function drafts(): string
    {
        $drafts = StockDocument::with('supplier')
            ->where('status', StockDocument::STATUS_DRAFT)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($drafts->isEmpty()) {
            return '✅ Незакритих чернеток немає.';
        }

        $lines = $drafts->map(function (StockDocument $d) {
            $pending = count($d->ai_state['pending'] ?? []);

            return '• '.\Carbon\Carbon::parse($d->operation_date)->format('d.m').' · '
                .($d->supplier?->name ?: 'постачальник не вказаний').' · '
                .$d->items()->count().' поз. · '.$this->money($d->total_sum)
                .($pending ? ' · чекає фасування: '.$pending : '')
                ."\n  ".StockDocumentResource::getUrl('edit', ['record' => $d]);
        });

        return "🧾 <b>Чернетки накладних</b> ({$drafts->count()})\n".$lines->implode("\n");
    }

    public function stock(string $query): string
    {
        if (mb_strlen(trim($query)) < 2) {
            return 'Напишіть, що шукати: <code>/склад курка</code>';
        }

        $found = $this->search($query);

        if ($found->isEmpty()) {
            return 'Не знайшов такого товару. Спробуйте іншу частину назви.';
        }

        $lines = $found->map(function ($item) {
            $unit = $item instanceof Packaging ? 'шт' : StockDocumentItem::canonUnit($item->unit ?? 'шт');
            $avg  = $item instanceof Ingredient ? $item->average_price : (float) $item->price;

            return '• '.$item->name.': <b>'.$this->num((float) $item->stock).' '.$unit.'</b>'
                .($avg > 0 ? ' · '.$this->money($avg).'/'.$unit : '');
        });

        return "📦 <b>Залишок</b>\n".$lines->implode("\n");
    }

    public function priceHistory(string $query): string
    {
        if (mb_strlen(trim($query)) < 2) {
            return 'Напишіть, що шукати: <code>/ціни курка</code>';
        }

        $item = $this->search($query)->first();

        if (! $item) {
            return 'Не знайшов такого товару.';
        }

        $unit    = $item instanceof Packaging ? 'шт' : StockDocumentItem::canonUnit($item->unit ?? 'шт');
        $history = $this->prices->history($item::class, $item->id);

        if ($history->isEmpty()) {
            return $item->name.': закупівель у базі немає.';
        }

        $previous = null;
        $lines    = $history->map(function ($row) use (&$previous, $unit) {
            $line = '• '.\Carbon\Carbon::parse($row->operation_date)->format('d.m.y').' · '
                .$this->money((float) $row->price).'/'.$unit
                .' · '.$this->num((float) $row->qty).' '.$unit
                .($row->supplier ? ' · '.$row->supplier : '');

            $previous = (float) $row->price;

            return $line;
        });

        $change = $history->count() > 1 && (float) $history->last()->price > 0
            ? ($history->first()->price - $history->last()->price) / $history->last()->price
            : null;

        $tail = $change === null ? '' : "\nЗа цей період ціна "
            .($change >= 0 ? 'зросла на +' : 'впала на −').round(abs($change) * 100).'%';

        return "💰 <b>{$item->name}</b>\n".$lines->implode("\n").$tail;
    }

    public function costs(): string
    {
        $today = AiRun::whereDate('created_at', now()->toDateString())->get();
        $month = AiRun::where('created_at', '>=', now()->startOfMonth())->get();
        $cap   = (float) config('services.anthropic.daily_cap_usd', 0);

        $failed = $today->where('status', '!=', 'ok')->count();

        return "🤖 <b>Витрати на ШІ</b>\n"
            .'Сьогодні: '.$this->usd($today->sum('cost_usd')).' · запусків '.$today->count()
            .($failed ? " · невдалих {$failed}" : '')."\n"
            .'Цього місяця: '.$this->usd($month->sum('cost_usd')).' · запусків '.$month->count()."\n"
            .($cap > 0 ? 'Денний ліміт: '.$this->usd($cap) : 'Денний ліміт не заданий');
    }

    /** Коротка панель: що зроблено сьогодні, що застрягло, чи все живе. */
    public function status(): string
    {
        $today = AiRun::whereDate('created_at', now()->toDateString())->get();
        $byPurpose = $today->where('status', 'ok')->groupBy('purpose')
            ->map(fn ($runs, $purpose) => match ($purpose) {
                AiRun::PURPOSE_INVOICE  => 'накладних: '.$runs->count(),
                AiRun::PURPOSE_OVERUSE  => 'голосових: '.$runs->count(),
                default                 => $purpose.': '.$runs->count(),
            })->values()->implode(' · ');

        $failed = $today->where('status', '!=', 'ok');
        $drafts = StockDocument::where('status', StockDocument::STATUS_DRAFT)->count();
        $shifts = \App\Models\EmployeeShift::whereDate('date', now()->toDateString())
            ->where('source', \App\Models\EmployeeShift::SOURCE_KITCHEN_CHAT)->count();
        $queue  = \App\Support\SchemaReady::has('jobs')
            ? \Illuminate\Support\Facades\DB::table('jobs')->count()
            : 0;

        $lines = [
            '🤖 <b>Стан на '.now()->format('H:i').'</b>',
            'ШІ: '.(OpsAi::switchedOn() ? 'увімкнений' : '⛔ вимкнений (/ші пуск)'),
            'Сьогодні: '.($byPurpose ?: 'нічого не розбирав'),
            'Чернеток чекає: '.$drafts.' · відміток «+» на кухні: '.$shifts,
            'Остання подія кухні: '.(\App\Services\Ai\KitchenAttendance::lastEvent() ?: 'ще не було'),
            'Черга задач: '.$queue.($queue > 5 ? ' ⚠️ схоже, застрягла' : ''),
            'Витрати сьогодні: '.$this->usd($today->sum('cost_usd')),
        ];

        if ($failed->isNotEmpty()) {
            $last = $failed->last();
            $lines[] = '⚠️ Невдалих спроб: '.$failed->count().'. Остання: '.mb_substr((string) $last->error, 0, 200);
        }

        return implode("\n", $lines);
    }

    /** `/ші стоп` і `/ші пуск` — зупинити або повернути розбір. */
    public function switchAi(string $argument): string
    {
        $argument = mb_strtolower(trim($argument));

        if (in_array($argument, ['стоп', 'stop', 'off', 'вимкни'], true)) {
            OpsAi::switch(false);

            return '⛔ ШІ вимкнено. Фото й голосові збережу, але розбирати не буду. Увімкнути: /ші пуск';
        }

        if (in_array($argument, ['пуск', 'start', 'on', 'увімкни'], true)) {
            OpsAi::switch(true);

            return '✅ ШІ увімкнено.';
        }

        return 'ШІ зараз '.(OpsAi::switchedOn() ? 'увімкнений' : 'вимкнений')
            .". Команди: <code>/ші стоп</code>, <code>/ші пуск</code>";
    }

    /** @return \Illuminate\Support\Collection<int, Ingredient|Packaging> */
    private function search(string $query): \Illuminate\Support\Collection
    {
        $like = '%'.trim($query).'%';

        return Ingredient::where('name', 'like', $like)->orderBy('name')->limit(6)->get()
            ->concat(Packaging::where('name', 'like', $like)->orderBy('name')->limit(3)->get())
            ->values();
    }

    private function money(float|string|null $value): string
    {
        return number_format((float) $value, 2, ',', ' ').' ₴';
    }

    private function usd(float|string|null $value): string
    {
        return '$'.number_format((float) $value, 2, '.', ' ');
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }
}
