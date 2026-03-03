<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'client_id',
        'status',
        'comment',
        'next_call_at',
        'refusal_reason',
        'user_id',
    ];

    protected $casts = [
        'next_call_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}