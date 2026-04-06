<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenDailyPlan extends Model
{
    protected $fillable = ['date', 'plan_json', 'generated_by'];

    protected $casts = [
        'date'      => 'date',
        'plan_json' => 'array',
    ];
}
