<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenNotification extends Model
{
    protected $fillable = [
        'type', 'order_id', 'client_id', 'client_name',
        'calories', 'schedule_type', 'project',
        'has_exclusions', 'duration', 'start_date',
        'message', 'read_at',
    ];

    protected $casts = [
        'has_exclusions' => 'boolean',
        'start_date'     => 'date',
        'read_at'        => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        $this->update(['read_at' => now()]);
    }

    public static function unreadCount(): int
    {
        return static::whereNull('read_at')->count();
    }

    public static function markAllAsRead(): void
    {
        static::whereNull('read_at')->update(['read_at' => now()]);
    }
}
