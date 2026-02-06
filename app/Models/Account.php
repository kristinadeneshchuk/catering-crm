<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'balance',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'balance' => 'decimal:2',
    ];
}