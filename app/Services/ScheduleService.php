<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ScheduleService
{
    public const CLOSED_SLOTS_KEY = 'closed_delivery_slots';

    /** За замовчуванням вихідні: курʼєр не їздить у сб ввечері та вс зранку */
    public const DEFAULT_CLOSED_SLOTS = ['saturday_evening', 'sunday_morning'];

    /** Всі можливі слоти доставки за тижнем */
    public const ALL_SLOTS = [
        'monday_morning',    'monday_evening',
        'tuesday_morning',   'tuesday_evening',
        'wednesday_morning', 'wednesday_evening',
        'thursday_morning',  'thursday_evening',
        'friday_morning',    'friday_evening',
        'saturday_morning',  'saturday_evening',
        'sunday_morning',    'sunday_evening',
    ];


    // Константи для зручності використання в коді
    public const EVERY_DAY_MORNING = 'every_day_morning';
    public const EVERY_DAY_EVENING = 'every_day_evening';
    public const INDIVIDUAL_MORNING = 'individual_morning';
    public const INDIVIDUAL_EVENING = 'individual_evening';

    /**
     * Список варіантів розкладу
     */
    public static function getScheduleTypes(): array
    {
        return [
            self::EVERY_DAY_MORNING => 'Кожен день, ранок',
            self::EVERY_DAY_EVENING => 'Кожен день, вечір',
            // Нові варіанти для індивідуального вибору дат
            self::INDIVIDUAL_MORNING => 'Індивідуальний графік, ранок',
            self::INDIVIDUAL_EVENING => 'Індивідуальний графік, вечір',
        ];
    }

    /**
     * Часові слоти
     */
    public static function getTimeSlots(?string $scheduleType): array
    {
        if (empty($scheduleType)) {
            return [];
        }

        // --- ВЕЧІРНІ СЛОТИ ---
        if (self::isEvening($scheduleType)) {
            $slots = [
                '18:00-19:30', '18:00-20:00', '18:00-21:00', '18:00-22:00',
                '18:30-20:30', '19:00-21:00', '19:00-22:00', '19:30-22:00',
                '20:00-21:00', '20:00-21:30', '20:00-22:00', '20:30-22:00', '21:00-22:00',
            ];
            return array_combine($slots, $slots);
        }

        // --- РАНКОВІ СЛОТИ ---
        // (Для 'every_day_morning' та 'individual_morning')
        $slots = [
            '06:00-07:00', '06:00-07:30', '06:00-08:00', '06:00-08:30',
            '06:00-09:00', '06:00-10:00', '06:30-08:00', '07:00-08:00',
            '07:00-08:30', '07:00-09:00', '07:00-09:30', '07:00-10:00',
            '07:30-09:00', '07:30-10:00', '08:00-09:00', '08:00-09:30',
            '08:00-10:00', '08:30-09:30', '08:30-10:00', '09:00-10:00',
        ];

        return array_combine($slots, $slots);
    }

    /**
     * Перевірка, чи це вечірній графік
     */
    public static function isEvening(?string $scheduleType): bool
    {
        if (!$scheduleType) return false;

        return in_array($scheduleType, [self::EVERY_DAY_EVENING, self::INDIVIDUAL_EVENING])
            || str_contains($scheduleType, 'evening')
            || str_contains($scheduleType, 'вечір');
    }

    // -------------------------------------------------------------------------
    // Closed delivery slots (вихідні курʼєрів) і обчислення дати доставки
    // -------------------------------------------------------------------------

    /**
     * Список закритих слотів (наприклад ['saturday_evening', 'sunday_morning']).
     * Кешується на 60с — інвалідується при збереженні налаштування.
     */
    public static function getClosedDeliverySlots(): array
    {
        return Cache::remember('schedule.closed_slots', 60, function () {
            $raw = Setting::where('key', self::CLOSED_SLOTS_KEY)->value('value');

            if ($raw === null) {
                return self::DEFAULT_CLOSED_SLOTS;
            }

            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

            return is_array($decoded) ? array_values(array_intersect($decoded, self::ALL_SLOTS)) : [];
        });
    }

    public static function clearClosedSlotsCache(): void
    {
        Cache::forget('schedule.closed_slots');
    }

    /**
     * Чи закритий слот в задану дату (наприклад "сб + evening")?
     */
    public static function isSlotClosed(Carbon $date, bool $isEvening): bool
    {
        $slotKey = strtolower($date->format('l')) . '_' . ($isEvening ? 'evening' : 'morning');

        return in_array($slotKey, self::getClosedDeliverySlots(), true);
    }

    /**
     * Фактична дата доставки для дня їжі.
     *
     * Базова формула: вечір => їжа D, доставка D-1; ранок => їжа D, доставка D.
     * Якщо отриманий слот закритий — сунемо доставку на день назад,
     * поки не знайдемо відкритий (захист від нескінченного циклу — макс 7 днів).
     */
    public static function computeDeliveryDate(Carbon $foodDate, bool $isEvening): Carbon
    {
        $delivery = $foodDate->copy();
        if ($isEvening) {
            $delivery->subDay();
        }

        // Захист від нескінченної рекурсії — обмежуємо 7 кроків
        for ($i = 0; $i < 7; $i++) {
            if (!self::isSlotClosed($delivery, $isEvening)) {
                return $delivery;
            }
            $delivery->subDay();
        }

        return $delivery; // fallback — повертаємо як є
    }

    /**
     * Людська назва слоту (для UI).
     */
    public static function slotLabel(string $slot): string
    {
        $map = [
            'monday'    => 'Понеділок',
            'tuesday'   => 'Вівторок',
            'wednesday' => 'Середа',
            'thursday'  => 'Четвер',
            'friday'    => "П'ятниця",
            'saturday'  => 'Субота',
            'sunday'    => 'Неділя',
        ];

        [$day, $part] = explode('_', $slot);

        return ($map[$day] ?? $day) . ' — ' . ($part === 'evening' ? 'вечір' : 'ранок');
    }
}