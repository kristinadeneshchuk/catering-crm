<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Памʼять агента: що вже зроблено/проаналізовано, щоб після перезапуску не забув. */
class AiAgentMemory extends Model
{
    protected $table = 'ai_agent_memory';

    protected $guarded = [];

    protected $casts = ['value' => 'array'];

    public function agent(): BelongsTo { return $this->belongsTo(AiAgent::class, 'ai_agent_id'); }
}
