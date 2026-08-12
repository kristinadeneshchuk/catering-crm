<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'channel',
        'project',
        'external_id',
        'username',
        'display_name',
        'avatar_url',
        'raw_meta',
    ];

    protected $casts = [
        'raw_meta' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
