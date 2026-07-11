<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class NewClientsStats extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.widgets.new-clients-stats';
    protected static ?int $sort = 5;
    protected int | string | array $columnSpan = 'full';

    public ?array $data = [];

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager']);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => auth()->user()->uiPref('newClientsStats.from', now()->subDays(13)->format('Y-m-d')),
            'to'   => auth()->user()->uiPref('newClientsStats.to',   now()->format('Y-m-d')),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('from')
                        ->label('З')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->live()
                        ->afterStateUpdated(fn () => auth()->user()->setUiPref('newClientsStats.from', $this->data['from'] ?? null)),

                    DatePicker::make('to')
                        ->label('По')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->live()
                        ->afterStateUpdated(fn () => auth()->user()->setUiPref('newClientsStats.to', $this->data['to'] ?? null)),
                ]),
            ])
            ->statePath('data');
    }

    protected function getViewData(): array
    {
        $today      = now()->startOfDay();
        $weekStart  = $today->copy()->subDays(6);
        $monthStart = $today->copy()->subDays(29);

        // Свій період — зі стану форми. Якщо один із кінців порожній — вважаємо custom не встановленим.
        $fromRaw = $this->data['from'] ?? null;
        $toRaw   = $this->data['to']   ?? null;

        $customFrom = $fromRaw ? Carbon::parse($fromRaw)->startOfDay() : null;
        $customTo   = $toRaw   ? Carbon::parse($toRaw)->endOfDay()     : null;

        // Захист від переверненого діапазону
        if ($customFrom && $customTo && $customFrom->gt($customTo)) {
            [$customFrom, $customTo] = [$customTo->copy()->startOfDay(), $customFrom->copy()->endOfDay()];
        }

        $hasCustom = $customFrom && $customTo;

        $countToday = Client::whereDate('created_at', $today)->count();
        $countWeek  = Client::where('created_at', '>=', $weekStart)->count();
        $countMonth = Client::where('created_at', '>=', $monthStart)->count();
        $countCustom = $hasCustom
            ? Client::whereBetween('created_at', [$customFrom, $customTo])->count()
            : 0;

        // Джерела — за обраний період, якщо він є, інакше за 30 днів
        $sourceFrom = $hasCustom ? $customFrom : $monthStart;
        $sourceTo   = $hasCustom ? $customTo   : now()->endOfDay();
        $sourceLabel = $hasCustom
            ? ($customFrom->format('d.m') . ' – ' . $customTo->format('d.m'))
            : '30 днів';

        $bySource = Client::select(
                DB::raw("COALESCE(NULLIF(TRIM(sales_source), ''), 'Без джерела') as source"),
                DB::raw('COUNT(*) as cnt'),
            )
            ->whereBetween('created_at', [$sourceFrom, $sourceTo])
            ->groupBy('source')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => ['source' => $r->source, 'count' => (int) $r->cnt])
            ->all();

        return [
            'countToday'    => $countToday,
            'countWeek'     => $countWeek,
            'countMonth'    => $countMonth,
            'countCustom'   => $countCustom,
            'hasCustom'     => $hasCustom,
            'customFromFmt' => $customFrom?->format('d.m'),
            'customToFmt'   => $customTo?->format('d.m'),
            'weekStartFmt'  => $weekStart->format('d.m'),
            'monthStartFmt' => $monthStart->format('d.m'),
            'todayFmt'      => $today->format('d.m'),
            'bySource'      => $bySource,
            'sourceLabel'   => $sourceLabel,
        ];
    }
}
