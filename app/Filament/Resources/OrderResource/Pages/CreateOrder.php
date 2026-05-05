<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\Client;
use App\Models\OrderDay;
use App\Models\CalorieRange;
use App\Models\TariffPrice;
use Carbon\Carbon;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    // Змінна для зберігання днів між етапами збереження
    protected array $customSelectedDays = [];

    // Додаткові раціони для сімейних замовлень
    protected array $additionalRations = [];

    public function mount(): void
    {
        parent::mount();

        $clientId = request('client_id');
        if ($clientId) {
            $client = Client::find($clientId);
            if ($client && $client->target_kcal) {
                $this->form->fill([
                    'client_id' => (int) $clientId,
                    'calories'  => $client->target_kcal,
                ]);
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * КРОК 1: Обробка даних ПЕРЕД створенням замовлення
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Намагаємося дістати дні з буфера
        $buffer = $data['selected_days_buffer'] ?? '[]';
        $selectedDays = json_decode($buffer, true);

        // Якщо дні є - зберігаємо їх у змінну класу
        if (!empty($selectedDays) && is_array($selectedDays)) {
            $this->customSelectedDays = $selectedDays;
            
            // Жорстко оновлюємо тривалість, щоб вона збігалася з кількістю вибраних днів
            $data['duration'] = count($selectedDays);
        }

        // 2. Видаляємо буфер, щоб не було помилки SQL (такої колонки немає в БД)
        unset($data['selected_days_buffer']);

        // 2а. Витягуємо додаткові раціони (сімейні замовлення) — їх зберігаємо окремо
        if (!empty($data['additional_rations']) && is_array($data['additional_rations'])) {
            $this->additionalRations = array_values(array_filter($data['additional_rations'], fn ($r) => !empty($r['tariff_id'])));
        }
        unset($data['additional_rations']);

        // 3. Рахуємо ціну (Server-Side страховка)
        $calories = (int) ($data['calories'] ?? 0);
        $tariffId = $data['tariff_id'] ?? null;
        $duration = (int) ($data['duration'] ?? 1);
        
        $pricePerDay = 0;

        if ($calories && $tariffId) {
            $range = CalorieRange::where('min_kcal', '<=', $calories)
                ->where('max_kcal', '>=', $calories)->first();

            if ($range) {
                $priceEntry = TariffPrice::where('tariff_id', $tariffId)
                    ->where('calorie_range_id', $range->id)->first();

                if ($priceEntry) {
                    $pricePerDay = (float) $priceEntry->price_per_day;
                }
            }
        }

        $data['total_price'] = $pricePerDay * $duration;

        return $data;
    }

    /**
     * КРОК 2: Створення днів ПІСЛЯ створення замовлення
     */
    protected function afterCreate(): void
    {
        $order = $this->record;

        // 🔥 ПЕРЕВІРКА: Чи ми зберегли "рвані" дні на кроці 1?
        if (!empty($this->customSelectedDays)) {
            
            // ВАРІАНТ А: Створюємо дні з календаря
            foreach ($this->customSelectedDays as $date) {
                OrderDay::firstOrCreate([
                    'order_id' => $order->id,
                    'date' => $date
                ]);
            }

        } elseif ($order->start_date && $order->duration > 0) {
            
            // ВАРІАНТ Б: Якщо календар був пустий, створюємо дні підряд
            $startDate = Carbon::parse($order->start_date);
            for ($i = 0; $i < $order->duration; $i++) {
                OrderDay::firstOrCreate([
                    'order_id' => $order->id,
                    'date' => $startDate->copy()->addDays($i)->format('Y-m-d')
                ]);
            }
        }
        
        $this->updateOrderStatus($order);

        // 🔥 Створюємо дочірні замовлення для додаткових раціонів (сімейні замовлення)
        if (!empty($this->additionalRations)) {
            $this->createChildOrders($order);
        }
    }

    /**
     * Створює дочірні замовлення для кожного додаткового раціону.
     * Той самий клієнт, ті самі дати/адреса/доставка — але різний тариф/калораж.
     */
    private function createChildOrders(\App\Models\Order $parentOrder): void
    {
        foreach ($this->additionalRations as $ration) {
            $tariffId    = $ration['tariff_id'] ?? null;
            $calories    = (int) ($ration['calories'] ?? 0);
            $menuType    = $ration['menu_type'] ?? 'cyclic';
            $project     = $ration['project'] ?? null;
            $menuPlanId  = $ration['menu_plan_id'] ?? null;

            // Якщо план не вказано явно — підтягуємо дефолтний з тарифу
            if (!$menuPlanId && $tariffId) {
                $menuPlanId = \App\Models\Tariff::find($tariffId)?->default_menu_plan_id;
            }
            // Останній фолбек — системний дефолтний план
            if (!$menuPlanId) {
                $menuPlanId = optional(\App\Models\MenuPlan::default())->id;
            }

            if (!$tariffId || !$calories) {
                continue;
            }

            // Рахуємо ціну для цього раціону
            $pricePerDay = 0;
            $range = \App\Models\CalorieRange::where('min_kcal', '<=', $calories)
                ->where('max_kcal', '>=', $calories)->first();
            if ($range) {
                $priceEntry = \App\Models\TariffPrice::where('tariff_id', $tariffId)
                    ->where('calorie_range_id', $range->id)->first();
                if ($priceEntry) {
                    $pricePerDay = (float) $priceEntry->price_per_day;
                }
            }

            $duration    = $parentOrder->duration ?: count($this->customSelectedDays);
            $totalPrice  = $pricePerDay * $duration;

            // Автоматично визначаємо проєкт із тарифу якщо не передали явно
            if (!$project) {
                $project = \App\Models\Tariff::find($tariffId)?->project;
            }

            $childOrder = \App\Models\Order::create([
                'client_id'       => $parentOrder->client_id,
                'parent_order_id' => $parentOrder->id,
                'tariff_id'       => $tariffId,
                'project'         => $project,
                'calories'        => $calories,
                'menu_type'       => $menuType,
                'menu_plan_id'    => $menuType === 'individual' ? null : $menuPlanId,
                'start_date'      => $parentOrder->start_date,
                'end_date'        => $parentOrder->end_date,
                'duration'        => $duration,
                'schedule_type'   => $parentOrder->schedule_type,
                'delivery_time'   => $parentOrder->delivery_time,
                'comment'         => $parentOrder->comment,
                'status'          => $parentOrder->status,
                'total_price'     => $totalPrice,
                'final_price'     => $totalPrice,
                'scale_factor'    => 1.0,
            ]);

            // Створюємо ті самі дні що й для батьківського замовлення
            $dates = !empty($this->customSelectedDays)
                ? $this->customSelectedDays
                : collect(range(0, $duration - 1))
                    ->map(fn ($i) => Carbon::parse($parentOrder->start_date)->addDays($i)->format('Y-m-d'))
                    ->toArray();

            foreach ($dates as $date) {
                OrderDay::firstOrCreate([
                    'order_id' => $childOrder->id,
                    'date'     => $date,
                ]);
            }

            $this->updateOrderStatus($childOrder);
        }
    }

    private function updateOrderStatus($order)
    {
        // Логіка повністю в Order::recomputeStatus() (paused-sticky враховано).
        // OrderDayObserver уже викликав це при створенні кожного OrderDay —
        // повторний виклик-страховка для випадку, коли днів у замовленні нема взагалі.
        $order->refresh()->recomputeStatus();
    }
}