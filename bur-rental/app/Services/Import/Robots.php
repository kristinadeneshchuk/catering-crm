<?php

namespace App\Services\Import;

/**
 * Читання robots.txt чужого сайту.
 *
 * Ми приходимо в гості: ходити туди, куди господар просив не ходити, — це і
 * неввічливо, і найшвидший спосіб отримати бан по IP чи по User-Agent. Тому
 * перед обходом читаємо правила і виконуємо їх.
 *
 * Розбір навмисно простий: `Disallow`, `Allow` і `Crawl-delay` для нашого
 * User-Agent або для `*`. Вайлдкарди `*` і `$` підтримані, решта тонкощів
 * стандарту для каталогу прокату не потрібна.
 */
class Robots
{
    /** @param list<string> $disallow */
    private function __construct(
        private readonly array $disallow,
        private readonly array $allow,
        public readonly ?int $crawlDelayMs,
    ) {}

    /** Порожні правила: сайт без robots.txt дозволяє все. */
    public static function allowAll(): self
    {
        return new self([], [], null);
    }

    public static function parse(string $body, string $userAgent = '*'): self
    {
        $groups = [];
        $current = [];

        foreach (preg_split('~\R~', $body) as $line) {
            $line = trim(preg_replace('~#.*$~', '', $line));

            if ($line === '') {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $field = strtolower(trim($field));
            $value = trim($value);

            if ($field === 'user-agent') {
                // Кілька User-agent підряд — це одна група правил на всіх.
                $current = $groups[$value] ?? [];
                $groups[$value] = &$current;
                unset($current);
                $current = &$groups[$value];

                continue;
            }

            if (in_array($field, ['disallow', 'allow', 'crawl-delay'], true) && isset($current)) {
                $current[] = [$field, $value];
            }
        }

        unset($current);

        // Точний збіг із нашим ім'ям важливіший за загальне правило.
        $rules = $groups[$userAgent] ?? $groups['*'] ?? [];

        $disallow = [];
        $allow = [];
        $delay = null;

        foreach ($rules as [$field, $value]) {
            match ($field) {
                'disallow' => $value !== '' ? $disallow[] = $value : null,
                'allow' => $allow[] = $value,
                'crawl-delay' => $delay = (int) round((float) $value * 1000),
                default => null,
            };
        }

        return new self($disallow, $allow, $delay);
    }

    /** Чи можна ходити за цією адресою. */
    public function allows(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        $path .= $query ? '?'.$query : '';

        // Явний Allow перебиває Disallow — так вимагає стандарт.
        foreach ($this->allow as $rule) {
            if ($this->matches($rule, $path)) {
                return true;
            }
        }

        foreach ($this->disallow as $rule) {
            if ($this->matches($rule, $path)) {
                return false;
            }
        }

        return true;
    }

    private function matches(string $rule, string $path): bool
    {
        $pattern = preg_quote($rule, '~');
        $pattern = str_replace('\*', '.*', $pattern);

        // `$` наприкінці правила означає точний кінець адреси.
        $anchored = str_ends_with($pattern, '\$');
        $pattern = $anchored ? substr($pattern, 0, -2).'$' : $pattern;

        return (bool) preg_match('~^'.$pattern.'~', $path);
    }
}
