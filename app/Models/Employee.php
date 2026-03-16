<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = ['name', 'position', 'base_rate', 'balance', 'is_active'];

    public function shifts()
{
    return $this->hasMany(EmployeeShift::class);
}
}
