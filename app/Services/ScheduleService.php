<?php

namespace App\Services;

class ScheduleService
{
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
                '20:00-21:30', '20:00-22:00', '20:30-22:00', '21:00-22:00',
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
}