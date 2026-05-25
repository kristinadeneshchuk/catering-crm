<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeShift extends Model
{
    protected $fillable = ['employee_id', 'date', 'rate', 'is_duty'];

    protected $casts = [
        'date'    => 'date',
        'rate'    => 'decimal:2',
        'is_duty' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
