<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAgentReport extends Model
{
    protected $guarded = [];

    public function agent(): BelongsTo { return $this->belongsTo(AiAgent::class, 'ai_agent_id'); }
    public function task(): BelongsTo { return $this->belongsTo(AiAgentTask::class, 'ai_agent_task_id'); }
}
