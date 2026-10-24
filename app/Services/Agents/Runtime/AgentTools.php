<?php

namespace App\Services\Agents\Runtime;

/** Набір інструментів одного агента: опис для моделі й виконання. */
interface AgentTools
{
    /** @return array<int, array{name: string, description: string, input_schema: array}> */
    public function definitions(): array;

    public function call(string $name, array $input): ToolResult;
}
