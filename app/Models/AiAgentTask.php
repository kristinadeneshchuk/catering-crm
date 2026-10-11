<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAgentTask extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'     => 'array',
        'due_at'      => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const STATUSES = [
        'new' => 'Нова', 'in_progress' => 'В роботі', 'done' => 'Готово', 'failed' => 'Помилка', 'cancelled' => 'Скасовано',
    ];

    public function agent(): BelongsTo { return $this->belongsTo(AiAgent::class, 'ai_agent_id'); }
}
