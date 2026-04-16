<?php

namespace App\Filament\Pages;

use App\Models\DeliveryRoute;
use App\Models\Setting;
use App\Traits\RestrictCookAccess;
use App\Services\AntLogisticsService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class LogisticsPage extends Page implements HasForms
{
    use InteractsWithForms, RestrictCookAccess;

    protected static ?string $navigationLabel = 'Логістика';
    protected static ?string $title           = 'Логістика — Маршрути та витрати';
    protected static string  $view            = 'filament.pages.logistics';
    protected static ?string $navigationGroup = 'Система';
    protected static ?int    $navigationSort  = 0;

    public ?array $data = [];
    public array $routes = [];

    // Підсумки
    public int   $totalRoutes   = 0;
    public int   $totalStops    = 0;
    public float $totalKm       = 0;
    public float $totalFuel     = 0;
    public float $totalCost     = 0;
    public float $totalAntCost  = 0;

    public function mount(): void
    {
        $this->form->fill([
            'date'  => now()->format('Y-m-d'),
            'shift' => 'all',
        ]);
        $this->loadRoutes();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(3)->schema([
                DatePicker::make('date')
                    ->label('Дата')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadRoutes()),

                Select::make('shift')
                    ->label('Зміна')
                    ->options(['all' => 'Всі', 'morning' => 'Ранкова', 'evening' => 'Вечірня'])
                    ->default('all')
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadRoutes()),
            ]),
        ])->statePath('data');
    }

    public function loadRoutes(): void
    {
        $date  = $this->data['date'] ?? now()->format('Y-m-d');
        $shift = $this->data['shift'] ?? 'all';

        $query = DeliveryRoute::whereDate('date', $date);
        if ($shift !== 'all') {
            $query->where('shift', $shift);
        }

        $routeCollection = $query->with('employee')->orderBy('ant_route_num')->get();

        $this->routes      = $routeCollection->toArray();
        $this->totalRoutes = $routeCollection->count();
        $this->totalStops  = (int) $routeCollection->sum('count_comps');
        $this->totalKm     = round((float) $routeCollection->sum(fn ($r) => $r->distance_fact ?? $r->distance_calc ?? 0), 1);
        $this->totalFuel   = round((float) $routeCollection->sum('fuel_city'), 2);
        $this->totalCost   = round((float) $routeCollection->sum('calculated_cost'), 2);
        $this->totalAntCost = round((float) $routeCollection->sum('ant_cost_route'), 2);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_clients')
                ->label('Синхронізувати клієнтів')
                ->requiresConfirmation()
                ->modalHeading('Синхронізація клієнтів в ANT Logistics')
                ->modalDescription('Всі активні клієнти будуть відправлені в ANT як Торгові точки. Продовжити?')
                ->action(function () {
                    try {
                        app(AntLogisticsService::class)->syncAllClients();
                        Notification::make()->title('Клієнтів синхронізовано в ANT')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Помилка синхронізації')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('push_orders')
                ->label('Відправити замовлення')
                ->form([
                    Grid::make(2)->schema([
                        \Filament\Forms\Components\DatePicker::make('date')
                            ->label('Дата доставки')
                            ->default(now()->addDay()->format('Y-m-d'))
                            ->required(),
                        Select::make('shift')
                            ->label('Зміна')
                            ->options(['all' => 'Всі', 'morning' => 'Ранок', 'evening' => 'Вечір'])
                            ->default('all')
                            ->required(),
                    ]),
                ])
                ->action(function (array $data) {
                    try {
                        $count = app(AntLogisticsService::class)->pushDailyOrders($data['date'], $data['shift']);
                        Notification::make()->title('Замовлення відправлено')->body("Відправлено точок: {$count}")->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Помилка відправки')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('pull_routes')
                ->label('Завантажити маршрути')
                ->form([
                    Grid::make(2)->schema([
                        \Filament\Forms\Components\DatePicker::make('date')
                            ->label('Дата доставки')
                            ->default(now()->addDay()->format('Y-m-d'))
                            ->required(),
                        Select::make('shift')
                            ->label('Зміна')
                            ->options(['all' => 'Всі', 'morning' => 'Ранок', 'evening' => 'Вечір'])
                            ->default('all')
                            ->required(),
                    ]),
                ])
                ->action(function (array $data) {
                    try {
                        $count = app(AntLogisticsService::class)->pullRouteAssignments($data['date'], $data['shift']);
                        Notification::make()->title('Маршрути завантажено')->body("Оновлено точок: {$count}")->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Помилка завантаження маршрутів')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('pull_route_details')
                ->label('Деталі маршрутів')
                ->form([
                    Grid::make(2)->schema([
                        \Filament\Forms\Components\DatePicker::make('date')
                            ->label('Дата маршруту')
                            ->default(now()->format('Y-m-d'))
                            ->required(),
                        Select::make('shift')
                            ->label('Зміна')
                            ->options(['all' => 'Всі', 'morning' => 'Ранок', 'evening' => 'Вечір'])
                            ->default('all')
                            ->required(),
                    ]),
                ])
                ->action(function (array $data) {
                    try {
                        $count = app(AntLogisticsService::class)->pullRouteDetails($data['date'], $data['shift']);
                        $this->form->fill(['date' => $data['date'], 'shift' => $data['shift']]);
                        $this->loadRoutes();
                        Notification::make()->title("Завантажено маршрутів: {$count}")->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Помилка: ' . $e->getMessage())->danger()->send();
                    }
                }),

            Action::make('settings')
                ->label('Ставки кур\'єрів')
                ->form([
                    Grid::make(3)->schema([
                        TextInput::make('courier_base_rate')
                            ->label('Базова ставка (₴)')
                            ->numeric()
                            ->default(fn () => Setting::where('key', 'courier_base_rate')->value('value') ?: 700),
                        TextInput::make('courier_base_stops')
                            ->label('Ліміт точок')
                            ->numeric()
                            ->default(fn () => Setting::where('key', 'courier_base_stops')->value('value') ?: 12),
                        TextInput::make('courier_extra_per_stop')
                            ->label('Доплата за точку (₴)')
                            ->numeric()
                            ->default(fn () => Setting::where('key', 'courier_extra_per_stop')->value('value') ?: 50),
                    ]),
                ])
                ->action(function (array $data) {
                    foreach (['courier_base_rate', 'courier_base_stops', 'courier_extra_per_stop'] as $key) {
                        Setting::updateOrCreate(['key' => $key], ['value' => $data[$key]]);
                    }
                    DeliveryRoute::all()->each(fn ($r) => $r->update([
                        'calculated_cost' => DeliveryRoute::calculateCourierCost($r->count_comps),
                    ]));
                    $this->loadRoutes();
                    Notification::make()->title('Ставки збережено та перераховано')->success()->send();
                }),
        ];
    }
}
