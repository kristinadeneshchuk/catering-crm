<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'attachments'       => 'array',
        'telegram_messages' => 'array',
    ];

    public function agent(): BelongsTo { return $this->belongsTo(AiAgent::class, 'ai_agent_id'); }
    public function toAgent(): BelongsTo { return $this->belongsTo(AiAgent::class, 'to_agent_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function proposal(): BelongsTo { return $this->belongsTo(AiProposal::class, 'ai_proposal_id'); }
}
