<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'date', 'is_completed',
        'address', 'address_entrance', 'address_apartment', 'address_floor', 'delivery_comment',
    ];

    protected $casts = [
        'date' => 'date',
        'is_completed' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}