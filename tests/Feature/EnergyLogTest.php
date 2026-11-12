<?php

namespace Tests\Feature;

use App\Models\EnergyTariff;
use App\Models\GeneratorRefuel;
use App\Models\MeterReading;
use App\Services\EnergySummary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnergyLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('transactions', fn (Blueprint $t) => $t->id());
        Schema::create('order_days', function (Blueprint $t) {
            $t->id();
            $t->date('date');
        });
        (require database_path('migrations/2026_11_12_100000_create_energy_log.php'))->up();

        EnergyTariff::create(['starts_on' => '2026-01-01', 'price' => 18]);
    }

    public function test_period_is_counted_from_previous_reading(): void
    {
        MeterReading::create(['taken_on' => '2026-06-19', 'reading' => 158887]);
        $r = MeterReading::create(['taken_on' => '2026-07-17', 'reading' => 160682]);

        $p = $r->period();
        $this->assertSame(1795, $p['kwh']);
        $this->assertSame(28, $p['days']);
        $this->assertEquals(32310.0, $p['cost']);
        $this->assertNull(MeterReading::orderBy('taken_on')->first()->period());
    }

    public function test_reading_cannot_go_backwards_or_jump_over_next(): void
    {
        MeterReading::create(['taken_on' => '2026-09-07', 'reading' => 165631]);
        MeterReading::create(['taken_on' => '2026-11-02', 'reading' => 169752]);

        foreach ([['2026-10-01', 165000], ['2026-10-01', 170000], ['2026-11-12', 169000]] as [$d, $v]) {
            try {
                MeterReading::create(['taken_on' => $d, 'reading' => $v]);
                $this->fail("Прийняло {$v} на {$d}");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        MeterReading::create(['taken_on' => '2026-10-01', 'reading' => 167805]);
        $this->assertSame(3, MeterReading::count());
    }

    public function test_tariff_change_does_not_touch_old_periods(): void
    {
        MeterReading::create(['taken_on' => '2026-09-07', 'reading' => 1000]);
        $old = MeterReading::create(['taken_on' => '2026-10-01', 'reading' => 1100]);
        EnergyTariff::create(['starts_on' => '2026-11-01', 'price' => 20]);
        $new = MeterReading::create(['taken_on' => '2026-11-02', 'reading' => 1200]);

        $this->assertEquals(1800.0, $old->period()['cost']);
        $this->assertEquals(2000.0, $new->period()['cost']);
    }

    public function test_month_summary_splits_period_by_days(): void
    {
        MeterReading::create(['taken_on' => '2026-09-21', 'reading' => 0]);
        MeterReading::create(['taken_on' => '2026-10-11', 'reading' => 2000]);   // 100 кВт/день
        MeterReading::create(['taken_on' => '2026-11-10', 'reading' => 5000]);   // 100 кВт/день
        GeneratorRefuel::create(['date' => '2026-10-12', 'liters' => 120, 'price_per_liter' => 94, 'amount' => 11280]);
        GeneratorRefuel::create(['date' => '2026-11-04', 'liters' => 116.13, 'price_per_liter' => 100, 'amount' => 11600]);
        \DB::table('order_days')->insert(array_fill(0, 100, ['date' => '2026-10-15']));

        $oct = EnergySummary::month('2026-10');
        $this->assertSame(3100, $oct['grid_kwh']);
        $this->assertEquals(55800, $oct['grid_cost']);
        $this->assertTrue($oct['complete']);
        $this->assertEquals(11280, $oct['fuel_cost']);
        $this->assertEquals(67080, $oct['total']);
        $this->assertEquals(670.8, $oct['per_ration']);

        $nov = EnergySummary::month('2026-11');
        $this->assertFalse($nov['complete']);
        $this->assertSame(900, $nov['grid_kwh']);
    }
}
