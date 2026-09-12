<?php

namespace App\Filament\Resources\CourierPayoutResource\Pages;

use App\Filament\Resources\CourierPayoutResource;
use App\Models\CourierPayout;
use App\Models\EmployeeBonus;
use App\Models\EmployeePenalty;
use App\Services\Couriers\CourierMileageService;
use App\Services\Couriers\CourierPayoutService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Правка виплати, куди веде ✏️ з Telegram.
 *
 * Правимо не знімок, а джерела: пробіг — через той самий сервіс, що й
 * Логістика, коригування — премією чи штрафом. Знімок після цього
 * перераховується, і повідомлення в обох чатах оновлюється.
 */
class EditCourierPayout extends EditRecord
{
    protected static string $resource = CourierPayoutResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Погоджене — вже рішення. Правити його означало б змінити суму,
        // яку власник щойно затвердив.
        abort_if($this->getRecord()->isLocked(), 403, 'Виплату вже погоджено — правки закриті.');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CourierPayout $record */
        $employee = $record->employee;
        $date     = $record->dateString();

        DB::transaction(function () use ($record, $employee, $date, $data) {
            foreach (($data['mileage'] ?? []) as $slot => $values) {
                app(CourierMileageService::class)->set($employee, $date, (string) $slot, $values);
            }

            $amount = (float) ($data['adjust_amount'] ?? 0);

            if (abs($amount) >= 0.01) {
                $model = $amount > 0 ? EmployeeBonus::class : EmployeePenalty::class;
                $model::create([
                    'employee_id' => $employee->id,
                    'amount'      => abs($amount),
                    'reason'      => (string) ($data['adjust_reason'] ?? 'Коригування виплати'),
                    'date'        => $date,
                ]);
            }

            $record->update(['comment' => $data['comment'] ?? null]);
        });

        $service = app(CourierPayoutService::class);
        $payout  = $service->refresh($employee, $date);
        $service->updateMessages($payout);

        return $payout;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
