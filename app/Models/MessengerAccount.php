<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessengerAccount extends Model
{
    use HasFactory;

    public const CHANNEL_TELEGRAM  = 'telegram';
    public const CHANNEL_INSTAGRAM = 'instagram';
    public const CHANNEL_VIBER     = 'viber';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_ERROR    = 'error';

    protected $fillable = [
        'channel',
        'display_name',
        'external_account_id',
        'credentials',
        'status',
        'last_error',
        'last_synced_at',
        'connected_by_user_id',
    ];

    // credentials шифруються автоматично — у БД лежить зашифрований blob
    protected $casts = [
        'credentials'    => 'encrypted:array',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'credentials',
    ];

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
