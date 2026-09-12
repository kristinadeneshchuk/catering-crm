<?php

namespace App\Services\Couriers;

use App\Models\CourierMileageLog;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Запис пробігу курʼєра — з рухом балансу на різницю компенсації.
 *
 * Пробіг вносять двома шляхами: менеджер на сторінці Логістики і курʼєр у
 * звіті зміни. Логіка «нова компенсація мінус стара → у борг компанії» має
 * бути одна, інакше два шляхи розійдуться і баланс попливе.
 */
class CourierMileageService
{
    public const FIELDS = ['start_km', 'end_km', 'fuel_price_per_liter'];

    public const SLOTS = [
        CourierMileageLog::SLOT_FULL,
        CourierMileageLog::SLOT_MORNING,
        CourierMileageLog::SLOT_EVENING,
    ];

    /**
     * @param  array<string, mixed>  $values  start_km / end_km / fuel_price_per_liter
     */
    public function set(Employee $employee, string $date, string $slot, array $values): ?CourierMileageLog
    {
        if (! in_array($slot, self::SLOTS, true)) {
            return null;
        }

        $values = array_intersect_key($values, array_flip(self::FIELDS));

        if ($values === []) {
            return null;
        }

        return DB::transaction(function () use ($employee, $date, $slot, $values) {
            $log = CourierMileageLog::where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->where('shift_slot', $slot)
                ->lockForUpdate()
                ->first();

            $oldComp = $log?->compensation ?? 0;

            if (! $log) {
                $log = new CourierMileageLog([
                    'employee_id'      => $employee->id,
                    'date'             => $date,
                    'shift_slot'       => $slot,
                    'amort_per_km'     => CourierMileageLog::currentAmortPerKm(),
                    'fuel_consumption' => (float) ($employee->fuel_consumption ?? 0),
                    'mileage_unit'     => $employee->mileage_unit ?? 'km',
                ]);
            }

            if ((float) ($log->fuel_consumption ?? 0) <= 0
                && (float) ($employee->fuel_consumption ?? 0) > 0) {
                $log->fuel_consumption = (float) $employee->fuel_consumption;
            }

            foreach ($values as $field => $value) {
                $log->{$field} = $this->normalize($field, $value);
            }

            $log->save();

            $delta = round($log->compensation - $oldComp, 2);

            if (abs($delta) > 0.001) {
                $employee->increment('balance', $delta);
            }

            return $log;
        });
    }

    private function normalize(string $field, mixed $value): int|float|null
    {
        $value = $value === '' ? null : $value;

        if ($field === 'fuel_price_per_liter') {
            return $value === null ? 0 : round((float) $value, 2);
        }

        return $value === null ? null : (int) $value;
    }
}
