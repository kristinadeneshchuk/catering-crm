<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'date', 'shift',
        'order_id', 'order_day_id', 'client_id', 'client_name', 'phone',
        'courier_name', 'courier_phone', 'car_number',
        'text', 'status',
        'response_code', 'response_status', 'message_id', 'error', 'response_body',
        'fingerprint', 'user_id',
    ];

    protected $casts = [
        'date'          => 'date',
        'response_code' => 'integer',
    ];

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeSent($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }
}
