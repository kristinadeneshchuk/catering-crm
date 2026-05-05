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

    public static function refusalReasons(): array
    {
        return [
            'taste'             => 'Не смачно',
            'out_of_city'       => 'Їду з міста',
            'vacation_business' => 'Їду у відпустку/відрядження',
            'abroad'            => 'Їду за кордон',
            'try_other'         => 'Хочу спробувати інші доставки',
            'pause'             => 'Зроблю паузу, як буде актуально напишу',
            'expensive'         => 'Дорого',
        ];
    }

    public static function refusalReasonLabel(?string $key): string
    {
        if (!$key) return '—';

        $reasons = static::refusalReasons() + [
            'vacation' => 'Відпустка / Від\'їзд',
            'other'    => 'Інше',
        ];

        return $reasons[$key] ?? $key;
    }
}