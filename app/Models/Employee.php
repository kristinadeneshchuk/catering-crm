<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = ['name', 'ant_driver_name', 'position', 'project_id', 'base_rate', 'balance', 'is_active', 'archived_at'];

    protected $casts = [
        'is_active'   => 'boolean',
        'archived_at' => 'datetime',
    ];

    // Посада-довідник (звʼязок за стабільним ключем, як Order->projectData за slug).
    // Назва positionData (не position!), щоб не конфліктувати з рядковою колонкою position.
    public function positionData(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Position::class, 'position', 'key');
    }

    // Бренд для рознесення ЗП у аналітику
    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    protected static function booted(): void
    {
        // Датовані оклади: ЗП помісячних посад фіксується в історії «діє з сьогодні»
        static::saved(function (Employee $employee) {
            if ($employee->wasRecentlyCreated || $employee->wasChanged('base_rate') || $employee->wasChanged('position')) {
                $pos = Position::where('key', $employee->position)->first();
                if ($pos && $pos->payment_type === 'per_month') {
                    RateHistory::record('salary:' . $employee->id, (float) $employee->base_rate);
                }
            }
        });
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function shifts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    public function deliveryRoutes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeliveryRoute::class);
    }

    public function penalties(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeePenalty::class);
    }

    public function mileageLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CourierMileageLog::class);
    }

    /**
     * Хронологія всіх рухів balance для цього співробітника.
     * Повертає масив подій (свіжіші — першими): зміни, штрафи, компенсації, виплати.
     */
    public function buildHistory(int $shiftLimit = 200): array
    {
        $events = [];

        // Зміни
        foreach ($this->shifts()->orderBy('date', 'desc')->limit($shiftLimit)->get() as $s) {
            $label = 'Зміна';
            if ($s->is_duty) $label .= ' ⭐ черговий';
            if ($s->is_half) $label .= ' (½)';
            $events[] = [
                'date'   => $s->date,
                'amount' => (float) $s->rate,
                'kind'   => 'shift',
                'label'  => $label,
            ];
        }

        // Штрафи
        foreach ($this->penalties()->orderBy('date', 'desc')->get() as $p) {
            $events[] = [
                'date'   => $p->date,
                'amount' => -(float) $p->amount,
                'kind'   => 'penalty',
                'label'  => 'Штраф' . ($p->reason ? ': ' . $p->reason : ''),
            ];
        }

        // Компенсація (тільки кур'єри)
        if ($this->position === 'courier') {
            foreach ($this->mileageLogs()->orderBy('date', 'desc')->get() as $l) {
                if ($l->compensation > 0) {
                    $events[] = [
                        'date'   => $l->date,
                        'amount' => (float) $l->compensation,
                        'kind'   => 'comp',
                        'label'  => "Компенсація ({$l->km} км × " . rtrim(rtrim(number_format((float) $l->amort_per_km, 2, '.', ''), '0'), '.') . "₴ + пальне " . (int) $l->fuel_uah . '₴)',
                    ];
                }
            }
        }

        // Виплати
        foreach (Transaction::where('employee_id', $this->id)->orderBy('date', 'desc')->get() as $t) {
            $events[] = [
                'date'   => $t->date,
                'amount' => -(float) abs($t->amount),
                'kind'   => 'payout',
                'label'  => 'Виплата' . ($t->comment ? ': ' . $t->comment : ''),
            ];
        }

        // Сортуємо за датою (новіше → старіше)
        usort($events, function ($a, $b) {
            $da = $a['date'] instanceof \Carbon\Carbon ? $a['date']->format('Y-m-d') : (string) $a['date'];
            $db = $b['date'] instanceof \Carbon\Carbon ? $b['date']->format('Y-m-d') : (string) $b['date'];
            return strcmp($db, $da);
        });

        return $events;
    }
}
