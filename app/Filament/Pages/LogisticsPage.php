<?php

namespace App\Filament\Pages;

use App\Models\CourierMileageLog;
use App\Models\DeliveryRoute;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Traits\RestrictCookAccess;
use App\Services\AntLogisticsService;
use App\Services\CourierSmsNotifier;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

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

    // SMS-сповіщення клієнтам про кур'єра
    public bool    $smsReady       = false;
    public ?string $smsBlockReason = null;
    public ?string $smsWarning     = null;
    public int     $smsSentCount   = 0;
    public bool    $smsCanSubmit   = false;

    public function mount(): void
    {
        // Запам'ятовуємо вибір дати per-user — щоб не скидалась на сьогодні щоразу.
        $this->form->fill([
            'date'  => auth()->user()->uiPref('logistics.date', now()->format('Y-m-d')),
            'shift'     => 'all',
            'sms_scope' => 'shift',
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

                // Робочий вечір: логіст побудував вечірні маршрути на сьогодні і
                // ранкові на завтра. Клієнтам обох потрібно сказати про курʼєра
                // одним заходом — розсилка після 21:00 уже не піде, а зранку
                // ранкових клієнтів попереджати запізно.
                Select::make('sms_scope')
                    ->label('Розсилка')
                    ->options([
                        'shift' => 'За обрану зміну',
                        CourierSmsNotifier::SHIFT_EVENING_PLUS_MORNING => 'Вечір сьогодні + ранок завтра',
                    ])
                    ->default('shift')
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadSmsState()),
            ]),
        ])->statePath('data');
    }

    public function loadRoutes(): void
    {
        $date  = $this->data['date'] ?? now()->format('Y-m-d');
        $shift = $this->data['shift'] ?? 'all';

        // Підхоплюємо курʼєрів, заведених/перейменованих уже після «Точки ↓»,
        // щоб не змушувати менеджера перетягувати маршрути заново.
        try {
            app(AntLogisticsService::class)->rematchRouteCouriers($date, $shift);
        } catch (\Throwable $e) {
            report($e);
        }

        $routeCollection = DeliveryRoute::filterByShift(
            DeliveryRoute::whereDate('date', $date)->with('employee')->orderBy('ant_route_num')->get(),
            $shift,
        );

        $this->routes      = $routeCollection->toArray();
        $this->totalRoutes = $routeCollection->count();
        $this->totalStops  = (int) $routeCollection->sum('count_comps');
        $this->totalCost   = round((float) $routeCollection->sum('calculated_cost'), 2);
        $this->totalAntCost = round((float) $routeCollection->sum('ant_cost_route'), 2);

        $this->loadSmsState();
    }

    /**
     * Стан кнопки «Відправити сповіщення клієнтам».
     * Тут тільки дешеві запити (маршрути + лічильник логів) — повний розбір
     * замовлень робиться вже при відкритті модалки.
     */
    /**
     * Яку зміну беремо для SMS: обрану на сторінці чи звʼязку «вечір + ранок».
     */
    protected function smsShift(): string
    {
        return ($this->data['sms_scope'] ?? 'shift') === CourierSmsNotifier::SHIFT_EVENING_PLUS_MORNING
            ? CourierSmsNotifier::SHIFT_EVENING_PLUS_MORNING
            : ($this->data['shift'] ?? 'all');
    }

    public function loadSmsState(): void
    {
        $date  = $this->data['date'] ?? now()->format('Y-m-d');
        $shift = $this->smsShift();

        // Якщо міграції ще не накатані (sms_logs / employees.phone) — не валимо
        // всю сторінку Логістики, а просто лишаємо кнопку неактивною.
        try {
            $readiness = app(CourierSmsNotifier::class)->readiness($date, $shift);

            $this->smsReady       = $readiness['ready'];
            $this->smsBlockReason = $readiness['reason'];
            $this->smsWarning     = $readiness['warning'] ?? null;

            // Рахуємо тільки ті відправки, що перетинаються з обраною зміною:
            // інакше після ранкової розсилки кнопка казала б «вже відправлені»
            // і на вечірній зміні, де ще нікому не слали.
            $segments = app(CourierSmsNotifier::class)->segments($date, $shift);

            $this->smsSentCount = SmsLog::sent()
                ->where(function ($q) use ($segments) {
                    foreach ($segments as [$segDate, $segShift]) {
                        $q->orWhere(fn ($sub) => $sub
                            ->whereDate('date', $segDate)
                            ->when($segShift !== 'all', fn ($x) => $x->whereIn('shift', ['all', $segShift])));
                    }
                })
                ->distinct()
                ->count('phone');
        } catch (\Throwable $e) {
            $this->smsReady       = false;
            $this->smsBlockReason = 'SMS-модуль недоступний: ' . $e->getMessage();
            $this->smsWarning     = null;
            $this->smsSentCount   = 0;
        }
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

    /**
     * Вміст модалки перед відправкою: скільки піде, кому повторно, що не так.
     */
    protected function buildSmsPreviewForm(): array
    {
        $date  = $this->data['date'] ?? now()->format('Y-m-d');
        $shift = $this->smsShift();

        $preview = app(CourierSmsNotifier::class)->preview($date, $shift);

        $willSend = $preview['new'] + $preview['resend'];

        // Якщо слати нікому, але є кому повторити — сабміт лишаємо активним,
        // бо адміністратор може поставити галочку «надіслати повторно всім».
        $this->smsCanSubmit = $willSend > 0 || $preview['unchanged'] > 0;

        $summary = '<div style="line-height:1.9;font-size:14px;">'
            . '<div><strong style="color:#22c55e;">Буде відправлено: ' . $willSend . '</strong>'
            . ' <span style="color:#71717a;">(нових: ' . $preview['new'] . ', повторно через зміну курʼєра: ' . $preview['resend'] . ')</span></div>';

        if ($preview['unchanged'] > 0) {
            $summary .= '<div style="color:#a1a1aa;">Пропустимо (вже надіслано, нічого не змінилось): ' . $preview['unchanged'] . '</div>';
        }

        if (! empty($preview['problems'])) {
            $summary .= '<div style="color:#f59e0b;">Проблемні замовлення (SMS не піде): ' . count($preview['problems']) . '</div>';
        }

        if ($this->smsWarning) {
            $summary .= '<div style="color:#f59e0b;">⚠️ ' . e($this->smsWarning) . '</div>';
        }

        $summary .= '</div>';

        $schema = [
            Placeholder::make('summary')->hiddenLabel()->content(new HtmlString($summary)),
        ];

        // Вибір отримувачів: за замовчуванням усі, але можна лишити 1-2 —
        // для тестової відправки, поки перевіряємо інтеграцію.
        if (! empty($preview['recipients'])) {
            $options = [];
            // Коли шлемо за два дні одразу, без дати список не читається.
            $multiDay = count(array_unique(array_column($preview['recipients'], 'date'))) > 1;

            foreach ($preview['recipients'] as $r) {
                $when = $multiDay
                    ? Carbon::parse($r['date'])->format('d.m')
                        . ($r['shift'] === 'morning' ? ' ранок' : ($r['shift'] === 'evening' ? ' вечір' : ''))
                        . ' · '
                    : '';

                $label = $when . $r['client_name'] . ' (+' . $r['phone'] . ') — ' . $r['courier_name'] . ', ' . $r['car_number'];
                if ($r['already_sent'] && ! $r['changed']) {
                    $label .= ' · вже надіслано';
                } elseif ($r['changed']) {
                    $label .= ' · курʼєр змінився';
                }
                $options[$r['key']] = $label;
            }

            $schema[] = CheckboxList::make('recipients_selected')
                ->label('Кому відправити')
                ->options($options)
                ->default(array_keys($options))
                ->bulkToggleable()
                ->columns(1)
                ->helperText('Зніміть зайві галочки, щоб відправити тільки вибраним (наприклад, 1-2 клієнтам для тесту). «Вже надіслано» підуть повторно лише з галочкою «Надіслати повторно всім».');
        }

        // Приклад тексту — щоб адміністратор побачив, що саме отримає клієнт.
        $sample = $preview['recipients'][0]['text'] ?? null;
        if ($sample !== null) {
            $schema[] = Placeholder::make('sample')
                ->label('Приклад SMS')
                ->content(new HtmlString(
                    '<pre style="white-space:pre-wrap;background:#18181b;border:1px solid #3f3f46;border-radius:8px;padding:10px;'
                    . 'color:#e4e4e7;font-size:13px;margin:0;">' . e($sample) . '</pre>'
                ));
        }

        if (! empty($preview['problems'])) {
            $rows = '';
            foreach (array_slice($preview['problems'], 0, 50) as $p) {
                $order = $p['order_id'] ? ' <span style="color:#71717a;">#' . e($p['order_id']) . '</span>' : '';
                $rows .= '<li style="margin-bottom:3px;"><strong>' . e($p['client']) . '</strong>' . $order
                       . ' — <span style="color:#f59e0b;">' . e($p['reason']) . '</span></li>';
            }

            $more = count($preview['problems']) > 50
                ? '<div style="color:#71717a;margin-top:6px;">…і ще ' . (count($preview['problems']) - 50) . '</div>'
                : '';

            $schema[] = Placeholder::make('problems')
                ->label('Замовлення з неповними даними')
                ->content(new HtmlString(
                    '<ul style="margin:0;padding-left:18px;font-size:13px;max-height:260px;overflow:auto;">' . $rows . '</ul>'
                    . $more
                    . '<div style="color:#71717a;margin-top:8px;font-size:12px;">По цих замовленнях SMS не відправляється. '
                    . 'Решта отримає сповіщення штатно.</div>'
                ));
        }

        if ($preview['unchanged'] > 0) {
            $schema[] = Checkbox::make('resend_all')
                ->label('Надіслати повторно всім, включно з тими, кому вже слали')
                ->helperText('За замовчуванням клієнти, у яких курʼєр і авто не змінились, повторну SMS не отримують.')
                ->default(false);
        }

        if ($willSend === 0 && $preview['unchanged'] === 0) {
            $schema[] = Placeholder::make('empty')
                ->hiddenLabel()
                ->content(new HtmlString('<div style="color:#ef4444;">Немає жодного замовлення з повними даними — відправляти нічого.</div>'));
        }

        return $schema;
    }

    /**
     * Журнал відправок за обрану дату.
     */
    protected function buildSmsLogTable(): HtmlString
    {
        $date = $this->data['date'] ?? now()->format('Y-m-d');

        $logs = SmsLog::whereDate('date', $date)->latest('id')->limit(200)->get();

        if ($logs->isEmpty()) {
            return new HtmlString('<div style="color:#71717a;">За ' . e(Carbon::parse($date)->format('d.m.Y')) . ' відправок не було.</div>');
        }

        $rows = '';
        foreach ($logs as $log) {
            $ok = $log->status === SmsLog::STATUS_SENT;

            // Відповідь шлюзу: код + статус, а під ним — сира відповідь у тайтлі.
            $apiInfo = $log->response_code !== null
                ? '<div style="color:#71717a;font-size:11px;">код ' . e($log->response_code)
                    . ($log->response_status ? ' · ' . e($log->response_status) : '') . '</div>'
                : '';

            $statusCell = ($ok
                ? '<span style="color:#22c55e;">✔ Надіслано</span>'
                : '<span style="color:#ef4444;">✖ ' . e($log->error ?: 'Помилка') . '</span>')
                . $apiInfo;

            $rows .= '<tr style="border-bottom:1px solid #27272a;vertical-align:top;">'
                . '<td style="padding:6px 8px;color:#a1a1aa;white-space:nowrap;">' . e($log->created_at?->format('d.m H:i')) . '</td>'
                . '<td style="padding:6px 8px;">' . e($log->client_name ?: '—')
                . ($log->order_id ? ' <span style="color:#71717a;">#' . e($log->order_id) . '</span>' : '') . '</td>'
                . '<td style="padding:6px 8px;color:#a1a1aa;white-space:nowrap;">+' . e($log->phone) . '</td>'
                . '<td style="padding:6px 8px;">' . e($log->courier_name ?: '—') . '</td>'
                . '<td style="padding:6px 8px;color:#a1a1aa;white-space:nowrap;">' . e($log->car_number ?: '—') . '</td>'
                . '<td style="padding:6px 8px;font-size:12px;color:#a1a1aa;white-space:pre-wrap;max-width:220px;"'
                . ' title="' . e($log->response_body ?: '') . '">' . e($log->text) . '</td>'
                . '<td style="padding:6px 8px;font-size:12px;">' . $statusCell . '</td>'
                . '</tr>';
        }

        return new HtmlString(
            '<div style="max-height:460px;overflow:auto;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            . '<thead><tr style="color:#71717a;text-align:left;border-bottom:1px solid #3f3f46;">'
            . '<th style="padding:6px 8px;">Час</th><th style="padding:6px 8px;">Клієнт</th>'
            . '<th style="padding:6px 8px;">Телефон</th><th style="padding:6px 8px;">Курʼєр</th>'
            . '<th style="padding:6px 8px;">Авто</th><th style="padding:6px 8px;">Текст SMS</th>'
            . '<th style="padding:6px 8px;">Статус</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div style="color:#52525b;font-size:11px;margin-top:8px;">Наведіть курсор на текст SMS, щоб побачити сиру відповідь TurboSMS.</div>'
            . '</div>'
        );
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
                        // Дефолти — з фільтра сторінки: «Точки ↓» працює саме по
                        // ньому, а тут раніше стояло «завтра / Всі». Дві кнопки
                        // легко тягнули різні дні — маршрути є, точок нема.
                        \Filament\Forms\Components\DatePicker::make('date')
                            ->label('Дата доставки')
                            ->default(fn () => $this->data['date'] ?? now()->format('Y-m-d'))
                            ->required(),
                        Select::make('shift')
                            ->label('Зміна')
                            ->options(['all' => 'Всі', 'morning' => 'Ранок', 'evening' => 'Вечір'])
                            ->default(fn () => $this->data['shift'] ?? 'all')
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
                        // Дефолти — з фільтра сторінки: «Точки ↓» працює саме по
                        // ньому, а тут раніше стояло «завтра / Всі». Дві кнопки
                        // легко тягнули різні дні — маршрути є, точок нема.
                        \Filament\Forms\Components\DatePicker::make('date')
                            ->label('Дата доставки')
                            ->default(fn () => $this->data['date'] ?? now()->format('Y-m-d'))
                            ->required(),
                        Select::make('shift')
                            ->label('Зміна')
                            ->options(['all' => 'Всі', 'morning' => 'Ранок', 'evening' => 'Вечір'])
                            ->default(fn () => $this->data['shift'] ?? 'all')
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

            Action::make('send_client_sms')
                ->label(fn () => $this->smsSentCount > 0
                    ? "Сповіщення вже відправлені ({$this->smsSentCount})"
                    : 'Відправити сповіщення клієнтам')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color(fn () => $this->smsSentCount > 0 ? 'gray' : 'success')
                ->disabled(fn () => ! $this->smsReady)
                ->modalHeading('Сповіщення клієнтам про курʼєра')
                ->modalSubmitActionLabel('Відправити SMS')
                ->modalWidth('2xl')
                ->form(fn () => $this->buildSmsPreviewForm())
                ->modalSubmitAction(fn ($action) => $action->disabled(! $this->smsCanSubmit))
                ->action(function (array $data) {
                    $date  = $this->data['date'] ?? now()->format('Y-m-d');
                    $shift = $this->smsShift();

                    $notifier = app(CourierSmsNotifier::class);

                    // Повторна перевірка на випадок, якщо маршрути змінились,
                    // поки модалка була відкрита.
                    $readiness = $notifier->readiness($date, $shift);
                    if (! $readiness['ready']) {
                        Notification::make()->title('Відправка неможлива')->body($readiness['reason'])->danger()->send();
                        return;
                    }

                    // null = «усім»; масив ключів — тільки вибраним у модалці.
                    $onlyKeys = array_key_exists('recipients_selected', $data)
                        ? array_values((array) $data['recipients_selected'])
                        : null;

                    try {
                        $result = $notifier->send($date, $shift, (bool) ($data['resend_all'] ?? false), $onlyKeys);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Помилка відправки')->body($e->getMessage())->danger()->send();
                        return;
                    }

                    $lines = [
                        "Сповіщення відправлено: {$result['sent']} клієнтам",
                        "Помилки відправки: {$result['failed']}",
                    ];

                    if ($result['skipped'] > 0) {
                        $lines[] = "Пропущено (вже надіслано, без змін): {$result['skipped']}";
                    }

                    if (($result['excluded'] ?? 0) > 0) {
                        $lines[] = "Не вибрано вручну: {$result['excluded']}";
                    }

                    if (! empty($result['errors'])) {
                        $lines[] = '';
                        $lines[] = '❌ Помилки:';
                        foreach (array_slice($result['errors'], 0, 10) as $err) {
                            $lines[] = "• {$err}";
                        }
                    }

                    if (! empty($result['problems'])) {
                        $lines[] = '';
                        $lines[] = '⚠️ Не відправлено (неповні дані): ' . count($result['problems']);
                        foreach (array_slice($result['problems'], 0, 10) as $p) {
                            $lines[] = "• {$p['client']} — {$p['reason']}";
                        }
                    }

                    $this->loadSmsState();

                    $hasIssues  = $result['failed'] > 0 || ! empty($result['problems']);
                    $nothingWent = $result['sent'] === 0 && $result['failed'] === 0;

                    if ($nothingWent) {
                        $level = 'warning';
                        $title = 'Нічого не відправлено';
                    } elseif ($result['sent'] === 0 && $hasIssues) {
                        $level = 'danger';
                        $title = 'Відправити не вдалося';
                    } elseif ($hasIssues) {
                        $level = 'warning';
                        $title = 'Відправлено із зауваженнями';
                    } else {
                        $level = 'success';
                        $title = 'Сповіщення відправлено';
                    }

                    Notification::make()
                        ->title($title)
                        ->body(implode("\n", $lines))
                        ->{$level}()
                        ->persistent()
                        ->send();
                }),

            Action::make('sms_log')
                ->label('Журнал SMS')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->modalHeading('Журнал відправок SMS')
                ->modalWidth('4xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрити')
                ->form([
                    Placeholder::make('log')
                        ->hiddenLabel()
                        ->content(fn () => $this->buildSmsLogTable()),
                ]),

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
