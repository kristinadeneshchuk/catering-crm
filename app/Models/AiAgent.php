<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** ШІ-агент: роль, правила, токен для API. Лише супер адмін. */
class AiAgent extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'allowed_types' => 'array',
        'is_active'     => 'boolean',
        'last_seen_at'  => 'datetime',
    ];

    public function tasks(): HasMany { return $this->hasMany(AiAgentTask::class); }
    public function proposals(): HasMany { return $this->hasMany(AiProposal::class); }
    public function reports(): HasMany { return $this->hasMany(AiAgentReport::class); }
    public function memory(): HasMany { return $this->hasMany(AiAgentMemory::class); }
    public function messages(): HasMany { return $this->hasMany(AiMessage::class); }

    /** Новий токен — показуємо один раз, у базі лише хеш. */
    public function issueToken(): string
    {
        $token = 'agt_' . $this->slug . '_' . Str::random(40);
        $this->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public static function byToken(?string $token): ?self
    {
        if (!$token) return null;

        return static::where('token_hash', hash('sha256', $token))->where('is_active', true)->first();
    }

    public function mayPropose(string $type): bool
    {
        return in_array($type, (array) $this->allowed_types, true);
    }
}
