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
}
