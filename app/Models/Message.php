<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND  = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const SENDER_CLIENT = 'client';
    public const SENDER_USER   = 'user';
    public const SENDER_SYSTEM = 'system';

    public const TYPE_TEXT     = 'text';
    public const TYPE_IMAGE    = 'image';
    public const TYPE_VIDEO    = 'video';
    public const TYPE_AUDIO    = 'audio';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_STICKER  = 'sticker';
    public const TYPE_LOCATION = 'location';
    public const TYPE_SYSTEM   = 'system';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ      = 'read';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'conversation_id',
        'direction',
        'sender_type',
        'sender_user_id',
        'type',
        'text',
        'external_message_id',
        'reply_to_message_id',
        'status',
        'error_message',
        'raw_payload',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'raw_payload'  => 'array',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::DIRECTION_OUTBOUND;
    }
}
