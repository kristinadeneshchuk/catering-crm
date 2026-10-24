<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Один виклик ШІ: навіщо, якою моделлю, скільки токенів і грошей, що вийшло.
 *
 * @see \App\Services\Ai\OpsAi
 */
class AiRun extends Model
{
    public const PURPOSE_INVOICE = 'invoice';
    public const PURPOSE_COURIER_REPORT = 'courier_report';
    public const PURPOSE_OVERUSE = 'overuse';
    public const PURPOSE_ACCOUNTING = 'accounting';
    public const PURPOSE_AGENT_MENU = 'agent_menu';
    public const PURPOSE_AGENT_CHATS = 'agent_chats';

    protected $guarded = [];

    protected $casts = [
        'input_meta' => 'array',
        'output'     => 'array',
        'cost_usd'   => 'decimal:4',
    ];
}
