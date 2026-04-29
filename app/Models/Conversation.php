<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Conversation extends Model
{
    use HasFactory;

    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'client_channel_id',
        'messenger_account_id',
        'channel',
        'external_chat_id',
        'assigned_user_id',
        'status',
        'last_message_at',
        'last_message_preview',
        'unread_count',
        'closed_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'closed_at'       => 'datetime',
        'unread_count'    => 'integer',
    ];

    public function clientChannel(): BelongsTo
    {
        return $this->belongsTo(ClientChannel::class);
    }

    public function messengerAccount(): BelongsTo
    {
        return $this->belongsTo(MessengerAccount::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    // Зручний доступ до Client через ClientChannel
    public function client(): HasOneThrough
    {
        return $this->hasOneThrough(
            Client::class,
            ClientChannel::class,
            'id',          // FK на client_channels (= client_channels.id)
            'id',          // FK на clients
            'client_channel_id', // local key on conversations
            'client_id'    // FK on client_channels.client_id
        );
    }

    public function markAsRead(): void
    {
        if ($this->unread_count > 0) {
            $this->update(['unread_count' => 0]);
        }
    }
}
