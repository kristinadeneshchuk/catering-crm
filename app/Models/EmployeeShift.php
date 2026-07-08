<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeShift extends Model
{
    // shift_slot: 'full' (одна зміна на день — стара поведінка), 'morning', 'evening'.
    public const SLOT_FULL = 'full';
    public const SLOT_MORNING = 'morning';
    public const SLOT_EVENING = 'evening';

    protected $fillable = ['employee_id', 'date', 'shift_slot', 'rate', 'is_duty', 'is_half'];

    // Не кастимо 'date' — щоб keyBy('date') / порівняння залишали формат 'Y-m-d'
    // (Carbon з cast='date' дає ключ '2026-05-25 00:00:00', що ламає пошук по даті).
    protected $casts = [
        'rate'    => 'decimal:2',
        'is_duty' => 'boolean',
        'is_half' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
