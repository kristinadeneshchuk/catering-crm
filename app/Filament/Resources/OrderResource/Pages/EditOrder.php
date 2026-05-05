<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\Tariff;
use App\Models\CalorieRange;
use App\Models\TariffPrice;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // 1. Об'єднуємо форму і "зв'язки" (транзакції) в єдині вкладки
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    // 2. Назва першої вкладки, де знаходиться сама форма замовлення
    public function getContentTabLabel(): ?string
    {
        return 'Інформація про замовлення';
    }

    /**
     * КРОК 0: Перед заповненням форми — підвантажуємо дочірні раціони
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $childOrders = Order::where('parent_order_id', $this->record->id)
            ->with('tariff')
            ->get();

        $data['additional_rations'] = $childOrders->map(fn ($child) => [
            'order_id'      => $child->id,
            'tariff_id'     => $child->tariff_id,
            'calories'      => $child->calories,
            'menu_type'     => $child->menu_type,
            'menu_plan_id'  => $child->menu_plan_id,
            'project'       => $child->project,
            'status'        => in_array($child->status, ['active', 'new']) ? 'active' : 'paused',
            'price_per_day' => $this->calcPricePerDay($child->tariff_id, (int) $child->calories),
        ])->toArray();

        return $data;
    }

    /**
     * КРОК 1: Перед збереженням — витягуємо additional_rations зі стану форми
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Repeater dehydrated(false), тому беремо з rawState
        unset($data['additional_rations']);

        // Hidden-поле статусу містить значення на момент відкриття форми. Якщо
        // паралельно OrderCalendar / OrderDayObserver уже оновили статус у БД,
        // запис форми перетер би їх. Status управляється виключно
        // Order::recomputeStatus() — тут просто не пускаємо його у update().
        unset($data['status']);

        // Завжди перераховуємо ціну з поточних значень форми (тариф + калораж +
        // тривалість). Інакше виходить рассинхрон: UI показує 1073 ₴/день,
        // а в БД лежить старий price_per_day (бо колись зберігся з іншим калоражем),
        // і Order::saving() множить старий price_per_day × duration → стара сума.
        $tariffId = $data['tariff_id'] ?? null;
        $calories = (int) ($data['calories'] ?? 0);
        $duration = (int) ($data['duration'] ?? $this->record->duration ?? 0) ?: 1;

        $pricePerDay = $this->calcPricePerDay($tariffId, $calories);
        if ($pricePerDay > 0) {
            $data['price_per_day'] = $pricePerDay;
            $data['total_price']   = $pricePerDay * $duration;
        }

        return $data;
    }

    /**
     * КРОК 2: Після збереження — синхронізуємо дочірні замовлення
     */
    protected function afterSave(): void
    {
        $order   = $this->record;
        $raw     = $this->form->getRawState();
        $rations = $raw['additional_rations'] ?? [];

        // Поточні дочірні замовлення в БД
        $existingChildren = Order::where('parent_order_id', $order->id)->get()->keyBy('id');
        $keptIds = [];

        foreach ($rations as $ration) {
            if (empty($ration['tariff_id'])) continue;

            $tariffId    = $ration['tariff_id'];
            $calories    = (int) ($ration['calories'] ?? 0);
            $menuType    = $ration['menu_type'] ?? 'cyclic';
            $project     = $ration['project'] ?? Tariff::find($tariffId)?->project;
            $childId     = $ration['order_id'] ?? null;
            $menuPlanId  = $ration['menu_plan_id'] ?? null;

            // Якщо план не вказано — підтягуємо з тарифу або системний дефолт
            if (!$menuPlanId && $tariffId) {
                $menuPlanId = Tariff::find($tariffId)?->default_menu_plan_id;
            }
            if (!$menuPlanId) {
                $menuPlanId = optional(\App\Models\MenuPlan::default())->id;
            }

            // Для індивідуального меню план не потрібен
            $effectivePlanId = $menuType === 'individual' ? null : $menuPlanId;

            $pricePerDay = $this->calcPricePerDay($tariffId, $calories);
            $duration    = $order->duration ?: $order->orderDays()->count();
            $totalPrice  = $pricePerDay * $duration;

            // Статус: 'active' або 'paused' з форми
            $newStatus = ($ration['status'] ?? 'active') === 'paused' ? 'paused' : 'active';

            if ($childId && $existingChildren->has($childId)) {
                // Оновлюємо існуюче дочірнє замовлення
                $existingChildren[$childId]->update([
                    'tariff_id'    => $tariffId,
                    'calories'     => $calories,
                    'menu_type'    => $menuType,
                    'menu_plan_id' => $effectivePlanId,
                    'project'      => $project,
                    'status'       => $newStatus,
                    'total_price'  => $totalPrice,
                    'final_price'  => $totalPrice,
                ]);
                $keptIds[] = $childId;
            } else {
                // Нový раціон — створюємо дочірнє замовлення
                $childOrder = Order::create([
                    'client_id'       => $order->client_id,
                    'parent_order_id' => $order->id,
                    'tariff_id'       => $tariffId,
                    'project'         => $project,
                    'calories'        => $calories,
                    'menu_type'       => $menuType,
                    'menu_plan_id'    => $effectivePlanId,
                    'start_date'      => $order->start_date,
                    'end_date'        => $order->end_date,
                    'duration'        => $duration,
                    'schedule_type'   => $order->schedule_type,
                    'delivery_time'   => $order->delivery_time,
                    'comment'         => $order->comment,
                    'status'          => $newStatus,
                    'total_price'     => $totalPrice,
                    'final_price'     => $totalPrice,
                    'scale_factor'    => 1.0,
                ]);

                // Копіюємо дні батьківського замовлення
                $order->orderDays()->pluck('date')->each(function ($date) use ($childOrder) {
                    OrderDay::firstOrCreate([
                        'order_id' => $childOrder->id,
                        'date'     => $date,
                    ]);
                });

                $keptIds[] = $childOrder->id;
            }
        }

        // Видаляємо раціони, які прибрали з форми
        $toDelete = $existingChildren->keys()->diff($keptIds);
        Order::whereIn('id', $toDelete)->each(fn ($o) => $o->delete());

        // Гарантуємо консистентний статус після всіх змін форми.
        // OrderDayObserver уже міг це зробити при додаванні/видаленні днів — це повторний
        // виклик-страховка для випадку, коли дні не змінювали, але статус мусить бути
        // переконаним (paused-sticky враховано всередині методу).
        $order->refresh()->recomputeStatus();
        Order::where('parent_order_id', $order->id)->each(fn ($child) => $child->recomputeStatus());
    }

    /**
     * Розраховує ціну/день для тарифу + калоражу.
     */
    private function calcPricePerDay(?int $tariffId, int $calories): float
    {
        if (!$tariffId || !$calories) return 0;

        $range = CalorieRange::where('min_kcal', '<=', $calories)
            ->where('max_kcal', '>=', $calories)->first();

        if (!$range) return 0;

        $entry = TariffPrice::where('tariff_id', $tariffId)
            ->where('calorie_range_id', $range->id)->first();

        return $entry ? (float) $entry->price_per_day : 0;
    }
}
