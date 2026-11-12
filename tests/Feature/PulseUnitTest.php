<?php

namespace Tests\Feature;

use App\Console\Commands\TelegramMorningPulse;
use Tests\TestCase;

class PulseUnitTest extends TestCase
{
    private function run_(array $rows, string $date = '2026-11-20')
    {
        $cmd = new TelegramMorningPulse();
        $m = new \ReflectionMethod($cmd, 'scaled');
        $m->setAccessible(true);

        return $m->invoke($cmd, collect($rows)->map(fn ($r) => (object) $r), \Carbon\Carbon::parse($date));
    }

    public function test_default_unit_leaves_numbers_as_is(): void
    {
        config(['services.telegram.pulse_unit' => 1]);
        $out = $this->run_([['project' => 'a', 'total' => 40, 'individual' => 7]]);

        $this->assertSame(40, (int) $out->sum('total'));
        $this->assertSame(7, (int) $out[0]->individual);
    }

    public function test_dates_before_start_stay_as_is(): void
    {
        config(['services.telegram.pulse_unit' => 1.5, 'services.telegram.pulse_unit_from' => '2026-11-13']);
        $rows = [['project' => 'a', 'total' => 82, 'individual' => 9]];

        $this->assertSame(82, (int) $this->run_($rows, '2026-11-12')->sum('total'));
        $this->assertSame(55, (int) $this->run_($rows, '2026-11-13')->sum('total'));
    }

    public function test_brand_rows_always_add_up_to_total(): void
    {
        config(['services.telegram.pulse_unit' => 1.5]);
        $out = $this->run_([
            ['project' => 'a', 'total' => 31, 'individual' => 8],
            ['project' => 'b', 'total' => 20, 'individual' => 5],
            ['project' => 'c', 'total' => 11, 'individual' => 0],
            ['project' => 'd', 'total' => 1, 'individual' => 1],
        ]);

        $this->assertSame((int) round(63 / 1.5), (int) $out->sum('total'));
        foreach ($out as $r) {
            $this->assertGreaterThan(0, $r->total);
            $this->assertLessThanOrEqual($r->total, $r->individual);
        }
    }
}
