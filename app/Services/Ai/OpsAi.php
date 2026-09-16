<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use App\Models\AiRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Звернення до Claude для операційних задач (docs/tz-ops-agent.md §7).
 *
 * Правила: ШІ лише читає й пояснює — усе, що стосується грошей і залишків,
 * рахує код. Кожен виклик пишеться в `ai_runs` (токени, вартість, результат).
 * Якщо ключа немає, ліміт витрат вичерпано або модель відповіла не за схемою —
 * повертаємо null, і процес іде далі без ШІ.
 */
class OpsAi
{
    /** Ціни за мільйон токенів, $. Оновлювати разом з моделлю. */
    private const PRICES = [
        'claude-opus-5'   => ['in' => 5.0, 'out' => 25.0, 'cache_read' => 0.5],
        'claude-sonnet-5' => ['in' => 2.0, 'out' => 10.0, 'cache_read' => 0.2],
    ];

    private ?Client $client = null;

    public function enabled(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    public function model(): string
    {
        return (string) config('services.anthropic.model', 'claude-opus-5');
    }

    /** Скільки витрачено на ШІ сьогодні, $. */
    public function spentToday(): float
    {
        return (float) AiRun::whereDate('created_at', now()->toDateString())->sum('cost_usd');
    }

    public function capReached(): bool
    {
        $cap = (float) config('services.anthropic.daily_cap_usd', 0);

        return $cap > 0 && $this->spentToday() >= $cap;
    }

    /**
     * Запит з текстом і зображеннями, відповідь за JSON-схемою.
     *
     * @param  array<int, string>  $imagePaths  шляхи у приватному сховищі (disk local)
     * @param  array<string, mixed>  $schema     JSON Schema очікуваної відповіді
     * @return array<string, mixed>|null
     */
    public function ask(
        string $purpose,
        string $system,
        string $prompt,
        array $schema,
        array $imagePaths = [],
        ?Model $subject = null,
        array $meta = [],
    ): ?array {
        if (! $this->enabled()) {
            $this->log($purpose, 'failed', $subject, $meta, error: 'ANTHROPIC_API_KEY не заданий');

            return null;
        }

        if ($this->capReached()) {
            $this->log($purpose, 'failed', $subject, $meta, error: 'Денний ліміт витрат на ШІ вичерпано');

            return null;
        }

        $started = microtime(true);

        try {
            $response = $this->send($system, $prompt, $schema, $imagePaths);
        } catch (\Throwable $e) {
            Log::warning('[OpsAi] запит не вдався', ['purpose' => $purpose, 'error' => $e->getMessage()]);
            $this->log($purpose, 'failed', $subject, $meta, error: $e->getMessage(), latencyMs: $this->ms($started));

            return null;
        }

        $data = $this->decode($response['text'] ?? '');

        $this->log(
            $purpose,
            $data === null ? 'invalid' : 'ok',
            $subject,
            $meta,
            output: $data,
            usage: $response['usage'] ?? [],
            latencyMs: $this->ms($started),
            error: $data === null ? 'Відповідь не за схемою: '.mb_substr((string) ($response['text'] ?? ''), 0, 500) : null,
        );

        return $data;
    }

    /**
     * Сам виклик API. Винесено окремо, щоб у тестах підмінити без мережі.
     *
     * @return array{text: string, usage: array<string, int>}
     */
    protected function send(string $system, string $prompt, array $schema, array $imagePaths): array
    {
        $content = [];

        foreach ($imagePaths as $path) {
            $content[] = ImageBlockParam::with(
                source: Base64ImageSource::with(
                    data: base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($path)),
                    mediaType: $this->mediaType($path),
                ),
            );
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        $message = $this->client()->messages->create(
            model: $this->model(),
            maxTokens: (int) config('services.anthropic.max_tokens', 8000),
            system: $system,
            messages: [['role' => 'user', 'content' => $content]],
            // Системна частина (довідник товарів) однакова для всіх накладних —
            // кешуємо, щоб не платити за неї щоразу.
            cacheControl: ['type' => 'ephemeral'],
            outputConfig: [
                'effort' => (string) config('services.anthropic.effort', 'medium'),
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        );

        $text = '';

        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text = $block->text;
                break;
            }
        }

        return [
            'text'  => $text,
            'usage' => [
                'input'      => (int) ($message->usage->inputTokens ?? 0),
                'output'     => (int) ($message->usage->outputTokens ?? 0),
                'cache_read' => (int) ($message->usage->cacheReadInputTokens ?? 0),
            ],
        ];
    }

    protected function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: (string) config('services.anthropic.key'),
            requestOptions: ['timeout' => (float) config('services.anthropic.timeout', 120)],
        );
    }

    private function decode(string $text): ?array
    {
        $data = json_decode(trim($text), true);

        return is_array($data) ? $data : null;
    }

    private function mediaType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            default => 'image/jpeg',
        };
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    public function cost(array $usage): float
    {
        $p = self::PRICES[$this->model()] ?? self::PRICES['claude-opus-5'];

        return round(
            ($usage['input'] ?? 0) / 1_000_000 * $p['in']
            + ($usage['output'] ?? 0) / 1_000_000 * $p['out']
            + ($usage['cache_read'] ?? 0) / 1_000_000 * $p['cache_read'],
            4,
        );
    }

    private function log(
        string $purpose,
        string $status,
        ?Model $subject,
        array $meta,
        ?array $output = null,
        array $usage = [],
        ?string $error = null,
        ?int $latencyMs = null,
    ): AiRun {
        return AiRun::create([
            'purpose'           => $purpose,
            'model'             => $this->model(),
            'status'            => $status,
            'subject_type'      => $subject ? $subject::class : null,
            'subject_id'        => $subject?->getKey(),
            'input_meta'        => $meta,
            'output'            => $output,
            'error'             => $error,
            'input_tokens'      => $usage['input'] ?? null,
            'output_tokens'     => $usage['output'] ?? null,
            'cache_read_tokens' => $usage['cache_read'] ?? null,
            'cost_usd'          => $usage ? $this->cost($usage) : null,
            'latency_ms'        => $latencyMs,
        ]);
    }
}
