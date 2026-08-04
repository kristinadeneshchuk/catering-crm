<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AntLogisticsService;
use Tests\TestCase;

/**
 * Матчинг «водій з ANT ↔ курʼєр CRM» (AntLogisticsService::matchDriverToEmployee).
 *
 * Кейс з прода 04.08: у картках курʼєрів «Імʼя в ANT» скоротили до перших імен
 * («Сергій», «Богдан»), ANT шле повні рядки («Сергій кур'єр», «Бортнік Богдан
 * кур'єр») — точний збіг розвалився, і всі маршрути відвʼязались. Матчинг має
 * переживати такі перейменування.
 */
class AntDriverMatchTest extends TestCase
{
    private function courier(string $name, ?string $ant, string $position = 'courier', bool $archived = false): Employee
    {
        return (new Employee())->forceFill([
            'name'            => $name,
            'ant_driver_name' => $ant,
            'position'        => $position,
            'archived_at'     => $archived ? now() : null,
        ]);
    }

    public function test_exact_ant_name_still_matches(): void
    {
        $pool = collect([$this->courier('Личко Володимир Валерійович(кур\'єр)', 'Личко Володимир кур\'єр')]);

        $this->assertSame(
            'Личко Володимир Валерійович(кур\'єр)',
            AntLogisticsService::matchDriverToEmployee('Личко Володимир кур\'єр', $pool)?->name
        );
    }

    public function test_matches_when_ant_field_was_shortened_to_first_name(): void
    {
        // ANT шле «Сергій кур'єр», у картці лишили тільки «Сергій».
        $pool = collect([$this->courier('Сергій кур\'єр', 'Сергій')]);

        $this->assertNotNull(AntLogisticsService::matchDriverToEmployee('Сергій кур\'єр', $pool));
    }

    public function test_matches_driver_words_as_subset_of_full_employee_name(): void
    {
        // ANT: «Бортнік Богдан кур'єр»; картка: ПІБ повний, ant-поле — «Богдан».
        $pool = collect([
            $this->courier('Бортнік Богдан Богданович (кур\'єр)', 'Богдан'),
            $this->courier('Фільчакова Христина (кур\'єр)', 'Христина'),
        ]);

        $this->assertSame(
            'Бортнік Богдан Богданович (кур\'єр)',
            AntLogisticsService::matchDriverToEmployee('Бортнік Богдан кур\'єр', $pool)?->name
        );
    }

    public function test_apostrophe_variants_do_not_break_matching(): void
    {
        // «курʼєр» (U+02BC) в ANT проти «кур'єр» (прямий апостроф) у картці.
        $pool = collect([$this->courier('Мірзабек (курʼєр)', 'Мірзабек')]);

        $this->assertNotNull(AntLogisticsService::matchDriverToEmployee('Мірзабек курʼєр', $pool));
    }

    public function test_unknown_driver_returns_null(): void
    {
        $pool = collect([$this->courier('Сергій кур\'єр', 'Сергій')]);

        $this->assertNull(AntLogisticsService::matchDriverToEmployee('Тарас', $pool));
    }

    public function test_ambiguous_driver_returns_null_instead_of_guessing(): void
    {
        // Два Богдани — краще не привʼязувати, ніж відправити клієнту чужий телефон.
        $pool = collect([
            $this->courier('Бортнік Богдан (кур\'єр)', 'Богдан 1'),
            $this->courier('Коваль Богдан (кур\'єр)', 'Богдан 2'),
        ]);

        $this->assertNull(AntLogisticsService::matchDriverToEmployee('Богдан кур\'єр', $pool));
    }

    public function test_subset_match_ignores_archived_and_non_couriers(): void
    {
        $pool = collect([
            $this->courier('Мірзабек (курʼєр)', null),
            $this->courier('Шеф кухар Мірзабек', null, 'cook'),
            $this->courier('Мірзабек старий', null, 'courier', archived: true),
        ]);

        $this->assertSame(
            'Мірзабек (курʼєр)',
            AntLogisticsService::matchDriverToEmployee('Мірзабек курʼєр', $pool)?->name
        );
    }
}
