<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAgentTask extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'     => 'array',
        'due_at'      => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'period_from' => 'date',
        'period_to'   => 'date',
        'cost_usd'    => 'decimal:4',
    ];

    public const STATUSES = [
        'new' => 'Нова', 'in_progress' => 'В роботі', 'waiting_human' => 'Чекає відповіді', 'done' => 'Готово', 'failed' => 'Помилка', 'cancelled' => 'Скасовано',
    ];

    /** Шаблони задач з картки клієнта/замовлення (docs/tz-ai-agents-in-crm.md §3.3). */
    public const TEMPLATES = [
        'menu_period'  => 'Скласти меню на період',
        'replacements' => 'Заміни на період',
        'chat_check'   => 'Перевірити переписку',
        'free'         => 'Вільна задача',
    ];

    public const OPEN = ['new', 'in_progress', 'waiting_human'];

    public function agent(): BelongsTo { return $this->belongsTo(AiAgent::class, 'ai_agent_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_task_id'); }
    public function events(): HasMany { return $this->hasMany(AiTaskEvent::class)->orderBy('id'); }
    public function proposals(): HasMany { return $this->hasMany(AiProposal::class); }

    public function isOpen(): bool { return in_array($this->status, self::OPEN, true); }

    /** Запис у тред задачі. */
    public function log(string $kind, ?string $text, array $data = [], ?int $userId = null, ?int $proposalId = null): AiTaskEvent
    {
        return $this->events()->create([
            'kind' => $kind, 'text' => $text, 'data' => $data ?: null,
            'user_id' => $userId, 'ai_proposal_id' => $proposalId,
        ]);
    }
}
