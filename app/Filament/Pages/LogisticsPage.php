<?php

namespace App\Filament\Pages;

use App\Models\CourierMileageLog;
use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\Setting;
use App\Traits\RestrictCookAccess;
use App\Services\AntLogisticsService;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

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

    // Підсумки маршрутів
    public int   $totalRoutes   = 0;
    public int   $totalStops    = 0;
    public float $totalCost     = 0;
    public float $totalAntCost  = 0;

    // Пробіг кур'єрів
    public array $mileageRows   = [];
    public float $totalMileageKm   = 0;
    public float $totalMileageFuel = 0;
    public float $totalMileageAmort = 0;
    public float $totalMileageComp  = 0;
    public float $amortPerKm = 1;

    public function mount(): void
    {
        // Запам'ятовуємо вибір дати per-user — щоб не скидалась на сьогодні щоразу.
        $this->form->fill([
            'date'  => auth()->user()->uiPref('logistics.date', now()->format('Y-m-d')),
            'shift' => 'all',
        ]);
        $this->loadRoutes();
        $this->loadMileage();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(3)->schema([
                DatePicker::make('date')
                    ->label('Дата')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function () {
                        auth()->user()->setUiPref('logistics.date', $this->data['date'] ?? null);
                        $this->loadRoutes();
                        $this->loadMileage();
                    }),

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
        $this->totalCost   = round((float) $routeCollection->sum('calculated_cost'), 2);
        $this->totalAntCost = round((float) $routeCollection->sum('ant_cost_route'), 2);
    }

    /**
     * Завантажити пробіг кур'єрів на обрану дату.
     * Показуємо всіх активних кур'єрів (а не тільки тих що в маршрутах) — менеджер вносить вручну.
     *
     * Режим "розділення" вмикається, коли існує хоча б один лог зі слотом
     * morning/evening для цього курʼєра на цю дату — тоді показуємо 2 рядки
     * (ранок + вечір). Інакше — 1 рядок (slot=full), стара поведінка.
     */
    public function loadMileage(): void
    {
        $date = $this->data['date'] ?? now()->format('Y-m-d');
        $this->amortPerKm = CourierMileageLog::currentAmortPerKm();

        $couriers = Employee::where('is_active', true)
            ->where('position', 'courier')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        $logsByEmp = CourierMileageLog::whereDate('date', $date)
            ->get()
            ->groupBy('employee_id');

        $rows = [];
        $sumKm = 0; $sumLiters = 0; $sumFuelCost = 0; $sumAmort = 0; $sumComp = 0;

        foreach ($couriers as $c) {
            $logs = $logsByEmp->get($c->id, collect());
            $isSplit = $logs->contains(fn ($l) => in_array($l->shift_slot, [
                CourierMileageLog::SLOT_MORNING,
                CourierMileageLog::SLOT_EVENING,
            ], true));

            $slots = $isSplit
                ? [CourierMileageLog::SLOT_MORNING, CourierMileageLog::SLOT_EVENING]
                : [CourierMileageLog::SLOT_FULL];

            $first = true;
            foreach ($slots as $slot) {
                $log = $logs->firstWhere('shift_slot', $slot);
                $km       = $log ? $log->km : 0;
                $liters   = $log ? $log->liters_used : 0;
                $fuelCost = $log ? $log->fuel_cost : 0;
                $amort    = $log ? $log->amortization : 0;
                $comp     = $log ? $log->compensation : 0;

                // Одиниця пробігу: беремо зі знімка на логу (для історичної консистентності),
                // а якщо логу ще нема — з поточного налаштування курʼєра.
                $unit = $log?->mileage_unit ?? ($c->mileage_unit ?? 'km');
                $rawDiff = $log ? $log->raw_diff : 0;

                $rows[] = [
                    'employee_id'          => $c->id,
                    'name'                 => $c->name,
                    'consumption'          => (float) ($c->fuel_consumption ?? 0),
                    'shift_slot'           => $slot,
                    'is_split'             => $isSplit,
                    'is_first_of_courier'  => $first,
                    'log_id'               => $log?->id,
                    'start_km'             => $log?->start_km,
                    'end_km'               => $log?->end_km,
                    'fuel_price_per_liter' => $log ? (float) $log->fuel_price_per_liter : 0,
                    'mileage_unit'         => $unit,
                    'raw_diff'             => $rawDiff,
                    'km'                   => $km,
                    'liters_used'          => $liters,
                    'fuel_cost'            => $fuelCost,
                    'amortization'         => $amort,
                    'compensation'         => $comp,
                ];

                $sumKm       += $km;
                $sumLiters   += $liters;
                $sumFuelCost += $fuelCost;
                $sumAmort    += $amort;
                $sumComp     += $comp;
                $first = false;
            }
        }

        $this->mileageRows = $rows;
        $this->totalMileageKm    = round($sumKm, 1);
        $this->totalMileageFuel  = round($sumFuelCost, 2);
        $this->totalMileageAmort = round($sumAmort, 2);
        $this->totalMileageComp  = round($sumComp, 2);
    }

    /**
     * Зберегти одне поле пробігу (inline-сейв).
     * При зміні компенсації коригуємо balance кур'єра на дельту.
     */
    public function saveMileage(int $employeeId, string $slot, string $field, $value): void
    {
        if (! in_array($field, ['start_km', 'end_km', 'fuel_price_per_liter'], true)) {
            return;
        }
        if (! in_array($slot, [
            CourierMileageLog::SLOT_FULL,
            CourierMileageLog::SLOT_MORNING,
            CourierMileageLog::SLOT_EVENING,
        ], true)) {
            return;
        }

        $date = $this->data['date'] ?? now()->format('Y-m-d');
        $employee = Employee::findOrFail($employeeId);

        $value = $value === '' || $value === null ? null : $value;
        if ($field === 'fuel_price_per_liter') {
            $value = $value === null ? 0 : round((float) $value, 2);
        } elseif ($value !== null) {
            $value = (int) $value;
        }

        DB::transaction(function () use ($employeeId, $date, $slot, $field, $value, $employee) {
            $log = CourierMileageLog::where('employee_id', $employeeId)
                ->whereDate('date', $date)
                ->where('shift_slot', $slot)
                ->lockForUpdate()
                ->first();

            $oldComp = $log?->compensation ?? 0;

            if (! $log) {
                $log = new CourierMileageLog([
                    'employee_id'      => $employeeId,
                    'date'             => $date,
                    'shift_slot'       => $slot,
                    'amort_per_km'     => CourierMileageLog::currentAmortPerKm(),
                    'fuel_consumption' => (float) ($employee->fuel_consumption ?? 0),
                    'mileage_unit'     => $employee->mileage_unit ?? 'km',
                ]);
            }

            if ((float) ($log->fuel_consumption ?? 0) <= 0
                && (float) ($employee->fuel_consumption ?? 0) > 0) {
                $log->fuel_consumption = (float) $employee->fuel_consumption;
            }

            $log->{$field} = $value;
            $log->save();

            $newComp = $log->compensation;
            $delta = round($newComp - $oldComp, 2);
            if (abs($delta) > 0.001) {
                $employee->increment('balance', $delta);
            }
        });

        $this->loadMileage();
    }

    /**
     * Розділити день кур'єра на ранок+вечір.
     * Якщо є лог 'full' — перейменовуємо його в 'morning' (зберігаємо всі значення),
     * створюємо порожній 'evening'. Якщо логу ще не було — просто позначаємо режим
     * створенням порожнього morning-логу.
     */
    public function splitDay(int $employeeId): void
    {
        $date = $this->data['date'] ?? now()->format('Y-m-d');
        $employee = Employee::findOrFail($employeeId);

        DB::transaction(function () use ($employeeId, $date, $employee) {
            $full = CourierMileageLog::where('employee_id', $employeeId)
                ->whereDate('date', $date)
                ->where('shift_slot', CourierMileageLog::SLOT_FULL)
                ->lockForUpdate()
                ->first();

            if ($full) {
                $full->update(['shift_slot' => CourierMileageLog::SLOT_MORNING]);
            }

            // Позначаємо режим існуванням morning-запису (створюємо, якщо не було).
            CourierMileageLog::firstOrCreate([
                'employee_id' => $employeeId,
                'date'        => $date,
                'shift_slot'  => CourierMileageLog::SLOT_MORNING,
            ], [
                'amort_per_km'     => CourierMileageLog::currentAmortPerKm(),
                'fuel_consumption' => (float) ($employee->fuel_consumption ?? 0),
                'mileage_unit'     => $employee->mileage_unit ?? 'km',
            ]);
        });

        $this->loadMileage();
    }

    /**
     * Обʼєднати ранок+вечір назад у одну зміну.
     * Компенсацію вечірнього логу знімаємо з балансу, лог видаляємо.
     * Ранковий лог перейменовуємо назад у 'full'.
     */
    public function mergeDay(int $employeeId): void
    {
        $date = $this->data['date'] ?? now()->format('Y-m-d');
        $employee = Employee::findOrFail($employeeId);

        DB::transaction(function () use ($employeeId, $date, $employee) {
            $evening = CourierMileageLog::where('employee_id', $employeeId)
                ->whereDate('date', $date)
                ->where('shift_slot', CourierMileageLog::SLOT_EVENING)
                ->lockForUpdate()
                ->first();

            if ($evening) {
                $comp = (float) $evening->compensation;
                if ($comp > 0.001) {
                    $employee->decrement('balance', $comp);
                }
                $evening->delete();
            }

            $morning = CourierMileageLog::where('employee_id', $employeeId)
                ->whereDate('date', $date)
                ->where('shift_slot', CourierMileageLog::SLOT_MORNING)
                ->lockForUpdate()
                ->first();

            if ($morning) {
                // Якщо в цей день чомусь уже є "full" (руками з іншої вкладки) — не робимо конфлікт.
                $existsFull = CourierMileageLog::where('employee_id', $employeeId)
                    ->whereDate('date', $date)
                    ->where('shift_slot', CourierMileageLog::SLOT_FULL)
                    ->exists();

                if ($existsFull) {
                    $comp = (float) $morning->compensation;
                    if ($comp > 0.001) {
                        $employee->decrement('balance', $comp);
                    }
                    $morning->delete();
                } else {
                    $morning->update(['shift_slot' => CourierMileageLog::SLOT_FULL]);
                }
            }
        });

        $this->loadMileage();
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
                        $result = app(AntLogisticsService::class)->pushDailyOrders($data['date'], $data['shift']);

                        $pushed  = (int) ($result['pushed'] ?? 0);
                        $total   = (int) ($result['total']  ?? 0);
                        $failed  = (int) ($result['failed'] ?? 0);
                        $skipped = $result['skipped'] ?? [];

                        $lines = ["Відправлено: {$pushed}/{$total}"];

                        if ($failed > 0) {
                            $lines[] = "Відхилено Ant: {$failed}";
                        }

                        if (!empty($skipped)) {
                            $lines[] = '';
                            $lines[] = '⚠️ Пропущено (кілька адрес без основної):';
                            foreach ($skipped as $s) {
                                $lines[] = "• {$s['client_name']} (id={$s['client_id']})";
                            }
                            $lines[] = '';
                            $lines[] = 'Виставте основну адресу в картці клієнта і повторіть відправку.';
                        }

                        $body  = implode("\n", $lines);
                        $level = (!empty($skipped) || $failed > 0) ? 'warning' : 'success';
                        $title = $level === 'success' ? 'Замовлення відправлено' : 'Відправлено з зауваженнями';

                        Notification::make()
                            ->title($title)
                            ->body($body)
                            ->{$level}()
                            ->persistent()
                            ->send();
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
                ->label('Точки маршрутів')
                ->action(function () {
                    $date  = $this->data['date']  ?? now()->format('Y-m-d');
                    $shift = $this->data['shift'] ?? 'all';
                    try {
                        $count = app(AntLogisticsService::class)->pullRouteDetails($date, $shift);
                        $this->loadRoutes();
                        $this->loadMileage();
                        Notification::make()->title("Завантажено маршрутів: {$count}")->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Помилка: ' . $e->getMessage())->danger()->send();
                    }
                }),

            Action::make('closed_slots')
                ->label('Вихідні курʼєрів')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->modalHeading('Закриті слоти доставки')
                ->modalDescription('Позначте слоти, в які курʼєри НЕ виїжджають. Доставка на ці дні автоматично переноситься на найближчий робочий день назад (подвійний раціон).')
                ->form([
                    CheckboxList::make('closed_slots')
                        ->label('Закриті слоти')
                        ->options(collect(ScheduleService::ALL_SLOTS)
                            ->mapWithKeys(fn ($s) => [$s => ScheduleService::slotLabel($s)])
                            ->all())
                        ->columns(2)
                        ->default(fn () => ScheduleService::getClosedDeliverySlots()),
                ])
                ->action(function (array $data) {
                    $slots = array_values(array_intersect($data['closed_slots'] ?? [], ScheduleService::ALL_SLOTS));
                    Setting::updateOrCreate(
                        ['key' => ScheduleService::CLOSED_SLOTS_KEY],
                        ['value' => json_encode($slots, JSON_UNESCAPED_UNICODE)],
                    );
                    ScheduleService::clearClosedSlotsCache();
                    Notification::make()->title('Вихідні збережено')->success()->send();
                }),

            Action::make('settings')
                ->label('Ставки кур\'єрів')
                ->form([
                    Grid::make(2)->schema([
                        TextInput::make('courier_base_stops')
                            ->label('Ліміт точок')
                            ->numeric()
                            ->helperText('Скільки точок входить у базову ставку кур\'єра')
                            ->default(fn () => Setting::where('key', 'courier_base_stops')->value('value') ?: 12),
                        TextInput::make('courier_extra_per_stop')
                            ->label('Доплата за точку (₴)')
                            ->numeric()
                            ->helperText('Скільки доплачувати за кожну точку понад ліміт')
                            ->default(fn () => Setting::where('key', 'courier_extra_per_stop')->value('value') ?: 50),
                        TextInput::make('amort_per_km')
                            ->label('Амортизація (₴/км)')
                            ->numeric()
                            ->step('0.01')
                            ->default(fn () => Setting::where('key', 'amort_per_km')->value('value') ?: 1)
                            ->helperText('Скільки нараховувати кур\'єру за кожен км його авто'),
                        TextInput::make('far_delivery_fee')
                            ->label('Доплата за дальню доставку (₴)')
                            ->numeric()
                            ->step('1')
                            ->default(fn () => Setting::where('key', 'far_delivery_fee')->value('value') ?: 150)
                            ->helperText('Менеджер відмічає галочку на дні замовлення — ця сума додається до вартості і до ЗП курьєра.'),
                    ]),
                ])
                ->action(function (array $data) {
                    foreach (['courier_base_stops', 'courier_extra_per_stop', 'amort_per_km', 'far_delivery_fee'] as $key) {
                        if (array_key_exists($key, $data)) {
                            Setting::updateOrCreate(['key' => $key], ['value' => $data[$key]]);
                        }
                    }
                    DeliveryRoute::with('employee')->get()->each(fn ($r) => $r->update([
                        'calculated_cost' => $r->recalcCost(),
                    ]));
                    $this->loadRoutes();
                    $this->loadMileage();
                    Notification::make()->title('Ставки збережено та перераховано')->success()->send();
                }),
        ];
    }
}
