<?php

namespace App\Services;

class ScheduleService
{
    /**
     * Список варіантів розкладу (Тільки 2 варіанти)
     */
    public static function getScheduleTypes(): array
    {
        return [
            'every_day_morning' => 'Кожен день, ранок',
            'every_day_evening' => 'Кожен день, вечір',
        ];
    }

    /**
     * Часові слоти (Залишаємо всі варіанти зі скріншотів)
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
        $slots = [
            '06:00-07:00', '06:00-07:30', '06:00-08:00', '06:00-08:30',
            '06:00-09:00', '06:00-10:00', '06:30-08:00', '07:00-08:00',
            '07:00-08:30', '07:00-09:00', '07:00-09:30', '07:00-10:00',
            '07:30-09:00', '07:30-10:00', '08:00-09:00', '08:00-09:30',
            '08:00-10:00', '08:30-09:30', '08:30-10:00', '09:00-10:00',
        ];

        return array_combine($slots, $slots);
    }

    public static function isEvening(string $scheduleType): bool
    {
        return str_contains($scheduleType, 'evening') || str_contains($scheduleType, 'вечір');
    }
}