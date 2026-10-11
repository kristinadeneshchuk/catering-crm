<?php

namespace App\Services\Agents;

use RuntimeException;

/** Виконання не вдалося з відомої причини — у який статус поставити пропозицію. */
class ProposalOutcome extends RuntimeException
{
    public function __construct(public string $status, string $message, public array $result = [])
    {
        parent::__construct($message);
    }
}
