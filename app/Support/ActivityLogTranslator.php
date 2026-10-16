<?php

namespace App\Support;

class ActivityLogTranslator
{
    public const SUBJECT_LABELS = [
        \App\Models\Order::class        => 'Замовлення',
        \App\Models\OrderDay::class     => 'День замовлення',
        \App\Models\OrderDayDish::class => 'Страва дня',
        \App\Models\Client::class       => 'Клієнт',
        \App\Models\Transaction::class  => 'Операція каси',
    ];

    public const EVENT_LABELS = [
        'created' => 'Створено',
        'updated' => 'Оновлено',
        'deleted' => 'Видалено',
    ];

    public const EVENT_COLORS = [
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
    ];

    public const ATTRIBUTE_LABELS = [
        // Order
        'client_id'        => 'Клієнт',
        'parent_order_id'  => 'Батьківське замовлення',
        'tariff_id'        => 'Тариф',
        'project'          => 'Проєкт',
        'is_paid'          => 'Оплачено',
        'start_date'       => 'Початок',
        'end_date'         => 'Кінець',
        'duration'         => 'Тривалість (днів)',
        'status'           => 'Статус',
        'calories'         => 'Калораж',
        'price_per_day'    => 'Ціна/день',
        'total_price'      => 'Сума',
        'comment'          => 'Коментар',
        'schedule_type'    => 'Тип графіка',
        'menu_type'        => 'Тип меню',
        'menu_plan_id'     => 'План меню',
        'delivery_time'    => 'Час доставки',
        'discount_type'    => 'Тип знижки',
        'discount_value'   => 'Розмір знижки',
        'discount_reason'  => 'Причина знижки',
        'discount_amount'  => 'Сума знижки',
        'final_price'      => 'Підсумкова ціна',

        // OrderDay
        'order_id'           => 'Замовлення',
        'date'               => 'Дата',
        'is_completed'       => 'Виконано',
        'address'            => 'Адреса',
        'address_entrance'   => 'Під\'їзд',
        'address_apartment'  => 'Квартира',
        'address_floor'      => 'Поверх',
        'delivery_comment'   => 'Коментар доставки',
        'ant_route_num'      => 'Маршрут №',
        'ant_route_pos'      => 'Позиція в маршруті',
        'ant_driver'         => 'Водій',
        'ant_delivery_group' => 'Група доставки',

        // OrderDayDish
        'meal_type_id'  => 'Прийом їжі',
        'dish_id'       => 'Страва',
        'weight_grams'  => 'Вага (г)',

        // Client
        'name'                => 'Ім\'я',
        'phone'               => 'Телефон',
        'email'               => 'Email',
        'sales_source'        => 'Джерело',
        'instagram_url'       => 'Instagram',
        'telegram_username'   => 'Telegram',
        'facebook_url'        => 'Facebook',
        'target_kcal'         => 'Цільові ккал',
        'production_comment'  => 'Коментар на кухню',
        'balance'             => 'Баланс',
        'has_cutlery'         => 'Прибори',
        'water_option'        => 'Вода',
        'manager_comment'     => 'Коментар менеджера',
        'ant_comp_id'         => 'Ant ID',

        // Transaction
        'type'              => 'Тип',
        'category'          => 'Стаття',
        'amount'            => 'Сума',
        'account.name'      => 'Рахунок',
        'employee.name'     => 'Співробітник',
        'stock_document_id' => 'Накладна',
    ];

    /** Значення, які людині незрозумілі як є. */
    public const VALUE_LABELS = [
        'type' => ['income' => 'Надходження', 'expense' => 'Витрата', 'refund' => 'Повернення'],
    ];

    public static function subject(?string $type): string
    {
        if (!$type) return '—';
        return self::SUBJECT_LABELS[$type] ?? class_basename($type);
    }

    public static function event(?string $event): string
    {
        return self::EVENT_LABELS[$event] ?? ($event ?: '—');
    }

    public static function eventColor(?string $event): string
    {
        return self::EVENT_COLORS[$event] ?? 'gray';
    }

    public static function attribute(string $key): string
    {
        return self::ATTRIBUTE_LABELS[$key] ?? $key;
    }

    /**
     * Перетворює properties (attributes/old) на масив рядків для UI.
     */
    public static function changes(array $properties): array
    {
        $new = $properties['attributes'] ?? [];
        $old = $properties['old'] ?? [];

        $keys = array_unique(array_merge(array_keys($new), array_keys($old)));
        $rows = [];

        foreach ($keys as $key) {
            $oldVal = self::formatValue(self::VALUE_LABELS[$key][$old[$key] ?? ''] ?? $old[$key] ?? null);
            $newVal = self::formatValue(self::VALUE_LABELS[$key][$new[$key] ?? ''] ?? $new[$key] ?? null);

            // При створенні порожні поля нічого не кажуть.
            if ($oldVal === '—' && $newVal === '—') {
                continue;
            }

            $rows[] = [
                'label' => self::attribute($key),
                'old'   => $oldVal,
                'new'   => $newVal,
            ];
        }

        return $rows;
    }

    private static function formatValue($value): string
    {
        if ($value === null || $value === '') return '—';
        if (is_bool($value)) return $value ? 'так' : 'ні';
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE);
        // Дати з моделей приходять у UTC: 2026-10-15T21:00:00.000000Z — це 16.10 за Києвом.
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value)) {
            $d = \Illuminate\Support\Carbon::parse($value)->setTimezone(config('app.timezone'));
            return $d->format($d->format('H:i:s') === '00:00:00' ? 'd.m.Y' : 'd.m.Y H:i');
        }
        return (string) $value;
    }
}
