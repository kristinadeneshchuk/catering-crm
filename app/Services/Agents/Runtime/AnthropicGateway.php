<?php

namespace App\Services\Agents\Runtime;

use Anthropic\Client;

/** Claude API: адаптивне мислення (за замовчуванням), effort, кеш незмінної частини. */
class AnthropicGateway implements ModelGateway
{
    private ?Client $client = null;

    public function step(string $model, string $effort, string $system, array $messages, array $tools): array
    {
        $message = $this->client()->messages->create(
            model: $model,
            maxTokens: (int) config('services.ai_agents.runtime.max_tokens', 16000),
            // Правила й опис інструментів однакові від кроку до кроку — кешуємо.
            system: [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            messages: $messages,
            tools: $tools,
            // Історія розмови росте з кожним кроком — кешуємо і її.
            cacheControl: ['type' => 'ephemeral'],
            outputConfig: ['effort' => $effort],
        );

        return [
            // Повертаємо блоки як є (з thinking) — їх треба віддати назад без змін.
            'content' => array_map(fn ($b) => json_decode(json_encode($b), true), $message->content),
            'stop_reason' => is_object($message->stopReason ?? null) ? $message->stopReason->value : ($message->stopReason ?? null),
            'model' => (string) (is_object($message->model) ? $message->model->value : $message->model),
            'usage' => [
                'input'       => (int) ($message->usage->inputTokens ?? 0),
                'output'      => (int) ($message->usage->outputTokens ?? 0),
                'cache_read'  => (int) ($message->usage->cacheReadInputTokens ?? 0),
                'cache_write' => (int) ($message->usage->cacheCreationInputTokens ?? 0),
            ],
        ];
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: (string) config('services.anthropic.key'),
            requestOptions: ['timeout' => (float) config('services.ai_agents.runtime.timeout', 600)],
        );
    }
}
