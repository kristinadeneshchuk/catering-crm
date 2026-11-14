<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Знімок останньої відправки зміни в ANT (одна на дату доставки і зміну). */
class AntPush extends Model
{
    protected $fillable = ['delivery_date', 'shift', 'pushed_at', 'points', 'user_id'];

    protected $casts = [
        'delivery_date' => 'date',
        'pushed_at'     => 'datetime',
        'points'        => 'array',
    ];
}
