<?php

namespace App\Services\Inbox;

/** Inbox відповів 429 і чекати довше не можна — повторити пізніше. */
class InboxRateLimited extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Inbox: ліміт запитів, повтор через {$retryAfter} с");
    }
}
