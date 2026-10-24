<?php

namespace App\Console\Commands;

use App\Models\ClientDietProfile;
use App\Models\Order;
use App\Services\Agents\IngredientGroupCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Профілі індивідуальних клієнтів з теки на Маку (individual-rations/profiles/*.md)
 * → client_diet_profiles (docs/tz-ai-agents-in-crm.md §7.2).
 *
 * Два кроки, бо YAML-парсер є лише в dev-залежностях:
 *   на Маку:    agents:import-profiles <тека profiles> --export=profiles.json [--modes=slug:mode,…]
 *   на сервері: agents:import-profiles profiles.json            (показати)
 *               agents:import-profiles profiles.json --write    (записати)
 * Цілі КБЖВ замовлень не чіпаємо — лише показуємо розбіжності.
 */
class AgentsImportProfiles extends Command
{
    protected $signature = 'agents:import-profiles {path : тека profiles або JSON} {--export= : куди зберегти JSON} {--modes= : slug:mode через кому} {--write : записати в базу}';

    protected $description = 'Перенести профілі харчування індивідуальних клієнтів у CRM (dry-run без --write)';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        return is_dir($path) ? $this->export($path) : $this->import($path);
    }

    private function export(string $dir): int
    {
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $this->error('Немає symfony/yaml — експорт робиться на Маку (dev-залежності).');

            return self::FAILURE;
        }
        $map = \Symfony\Component\Yaml\Yaml::parseFile(dirname(rtrim($dir, '/')) . '/slug_crm.yaml') ?? [];
        $modes = collect(explode(',', (string) $this->option('modes')))->filter()
            ->mapWithKeys(fn ($pair) => [trim(explode(':', $pair)[0]) => trim(explode(':', $pair)[1] ?? '')])->all();

        $out = [];
        foreach (glob(rtrim($dir, '/') . '/*.md') as $file) {
            if (str_starts_with(basename($file), '_')) continue;
            if (!preg_match('/^---\s*\n(.*?)\n---/s', (string) file_get_contents($file), $m)) continue;
            $y = \Symfony\Component\Yaml\Yaml::parse($m[1]);
            $slug = $y['slug'] ?? basename($file, '.md');
            $out[] = $y + ['slug' => $slug, 'client_id' => $map[$slug] ?? null, 'mode' => $modes[$slug] ?? ($y['mode'] ?? null)];
        }

        file_put_contents((string) $this->option('export'), json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('Профілів: ' . count($out) . ' → ' . $this->option('export'));
        foreach ($out as $p) if (!$p['client_id']) $this->warn("{$p['slug']}: немає client_id у slug_crm.yaml — пропуститься");

        return self::SUCCESS;
    }

    private function import(string $file): int
    {
        $profiles = json_decode((string) @file_get_contents($file), true);
        if (!is_array($profiles)) {
            $this->error('Не JSON: ' . $file);

            return self::FAILURE;
        }

        $groups = IngredientGroupCatalog::all();
        $rows = [];
        $seen = [];
        foreach ($profiles as $y) {
            $cid = (int) ($y['client_id'] ?? 0);
            $slug = $y['slug'] ?? '?';
            if (!$cid || !DB::table('clients')->where('id', $cid)->exists()) { $this->warn("{$slug}: клієнта #{$cid} немає — пропущено"); continue; }
            if (isset($seen[$cid])) { $this->warn("{$slug}: клієнт #{$cid} уже має профіль «{$seen[$cid]}» (пара/родина?) — пропущено, внесіть вручну"); continue; }
            $seen[$cid] = $slug;

            $stop = (array) ($y['stop'] ?? []);
            $avoid = (array) ($y['avoid'] ?? []);
            $cook = (array) ($y['cooking'] ?? []);
            $data = [
                'mode'              => array_key_exists((string) ($y['mode'] ?? ''), ClientDietProfile::MODES) ? $y['mode'] : 'cycle_replacements',
                'excluded_groups'   => array_values(array_filter($stop, fn ($c) => isset($groups[$c]))),
                'avoid'             => array_values(array_map(fn ($c) => isset($groups[$c]) ? ['group_slug' => $c, 'text' => $c] : ['text' => $c], $avoid)),
                'want_more'         => array_values(array_merge((array) ($y['want_more'] ?? []), (array) ($y['liked_dishes'] ?? []))),
                'rules'             => array_values(array_map(fn ($t) => ['kind' => 'text', 'text' => $t], (array) ($cook['rules'] ?? []))),
                'kitchen_memo'      => $this->memo($cook, (array) ($y['format'] ?? [])),
                'notes'             => $this->notes($y, array_values(array_filter($stop, fn ($c) => !isset($groups[$c])))),
            ];
            $rows[] = ['slug' => $slug, 'client_id' => $cid, 'data' => $data, 'macros' => $y['macros'] ?? null, 'kcal' => $y['kcal'] ?? null];
        }

        foreach ($rows as $r) {
            $order = Order::where('client_id', $r['client_id'])->whereIn('status', ['new', 'active', 'paused'])->latest('start_date')->first();
            $this->line(sprintf('<info>%s</info> #%d · %s · не можна (групи): %s · краще ні: %d · правил: %d%s',
                $r['slug'], $r['client_id'], $r['data']['mode'], implode(', ', $r['data']['excluded_groups']) ?: '—',
                count($r['data']['avoid']), count($r['data']['rules']),
                ClientDietProfile::where('client_id', $r['client_id'])->exists() ? ' · <comment>профіль уже є — оновиться</comment>' : ''));
            $m = (array) $r['macros'];
            if ($order && $m && ((int) ($m['p'] ?? 0) !== (int) $order->target_protein_g || (int) ($m['f'] ?? 0) !== (int) $order->target_fats_g || (int) ($m['c'] ?? 0) !== (int) $order->target_carbs_g)) {
                $this->line("    ↳ цілі БЖВ: у профілі {$m['p']}/{$m['f']}/{$m['c']}, у замовленні #{$order->id} " . ($order->target_protein_g ?? '—') . '/' . ($order->target_fats_g ?? '—') . '/' . ($order->target_carbs_g ?? '—') . ' — не змінюю, вирішує менеджер');
            }
        }

        if (!$this->option('write')) {
            $this->warn('Dry-run. Записати: --write');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) ClientDietProfile::updateOrCreate(['client_id' => $r['client_id']], $r['data']);
        });
        $this->info('Записано профілів: ' . count($rows));

        return self::SUCCESS;
    }

    /** Памʼятка кухні: як готувати й подавати. Без грошей і без згадок ШІ. */
    private function memo(array $cook, array $format): ?string
    {
        $lines = [];
        if ($cook['ok'] ?? []) $lines[] = 'Можна: ' . implode(', ', $cook['ok']);
        if ($cook['no'] ?? []) $lines[] = 'Не готувати: ' . implode(', ', $cook['no']);
        foreach (['spice' => 'Спеції', 'salt' => 'Сіль', 'sauces' => 'Соуси'] as $k => $label) {
            if (!empty($cook[$k]) && !in_array($cook[$k], ['невідомо', 'звичайно'], true)) $lines[] = "{$label}: {$cook[$k]}";
        }
        foreach ($format as $f) $lines[] = 'Подача: ' . $f;

        return $lines ? implode("\n", $lines) : null;
    }

    private function notes(array $y, array $unknownStop): ?string
    {
        $lines = [];
        foreach (['eats' => 'Хто їсть', 'delivery' => 'Доставка', 'days' => 'Дні'] as $k => $label) {
            if (!empty($y[$k])) $lines[] = "{$label}: {$y[$k]}";
        }
        if ($unknownStop) $lines[] = 'Не можна (без групи, перевірити виключення): ' . implode(', ', $unknownStop);
        foreach ((array) ($y['stop_detail'] ?? []) as $s) $lines[] = 'Деталі: ' . $s;
        foreach ((array) ($y['disliked_dishes'] ?? []) as $s) $lines[] = 'Не сподобалось: ' . $s;
        foreach ((array) ($y['open_questions'] ?? []) as $s) $lines[] = 'Відкрите питання: ' . $s;

        return $lines ? implode("\n", $lines) : null;
    }
}
