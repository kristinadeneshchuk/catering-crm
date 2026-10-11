<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RateHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeRateHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('employees');
        Schema::create('employees', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('position')->nullable(); $t->decimal('base_rate', 10, 2)->default(0);
            $t->decimal('balance', 10, 2)->default(0); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::dropIfExists('rate_history');
        Schema::create('rate_history', function (Blueprint $t) {
            $t->id(); $t->string('scope'); $t->decimal('value', 10, 2); $t->date('effective_from'); $t->timestamps();
        });
        Schema::dropIfExists('positions');
        Schema::create('positions', function (Blueprint $t) { $t->id(); $t->string('key'); $t->string('payment_type')->nullable(); $t->timestamps(); });
    }

    public function test_a_new_rate_does_not_rewrite_past_days(): void
    {
        $this->travelTo('2026-10-01');
        $e = Employee::create(['name' => 'Кухар', 'position' => 'cook', 'base_rate' => 1800]);
        $this->assertSame(1800.0, $e->rateOn('2026-09-15'));

        $this->travelTo('2026-10-12');
        $e->update(['base_rate' => 2100]);

        $this->assertSame(1800.0, $e->fresh()->rateOn('2026-10-11'));
        $this->assertSame(2100.0, $e->fresh()->rateOn('2026-10-12'));
        $this->assertSame(2100.0, $e->fresh()->rateOn('2026-10-20'));
        $this->assertSame(2, RateHistory::where('scope', 'rate:'.$e->id)->count());
    }
}
