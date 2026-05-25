<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeShift extends Model
{
    protected $fillable = ['employee_id', 'date', 'rate', 'is_duty'];

    // Не кастимо 'date' — щоб keyBy('date') / порівняння залишали формат 'Y-m-d'
    // (Carbon з cast='date' дає ключ '2026-05-25 00:00:00', що ламає пошук по даті).
    protected $casts = [
        'rate'    => 'decimal:2',
        'is_duty' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
