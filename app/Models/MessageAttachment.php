<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'file_path',
        'file_url',
        'file_name',
        'mime_type',
        'size_bytes',
        'thumbnail_path',
        'duration_seconds',
    ];

    protected $casts = [
        'size_bytes'       => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    // Публічний URL: спочатку локальний файл, у фолбеку — оригінальний URL з каналу
    public function getUrlAttribute(): ?string
    {
        if ($this->file_path) {
            return Storage::url($this->file_path);
        }

        return $this->file_url;
    }
}
