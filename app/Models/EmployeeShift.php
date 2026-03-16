<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeShift extends Model
{
    protected $fillable = ['employee_id', 'date', 'rate'];

public function employee()
{
    return $this->belongsTo(Employee::class);
}
}
