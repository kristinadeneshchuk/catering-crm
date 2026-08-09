<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Імпорт JSON від scrape:budprokat у каталог.
 *
 * Усе імпортоване приходить НЕопублікованим: чужий прайс — це чернетка,
 * менеджер в адмінці звіряє ціну, категорію й опис і вмикає позицію вручну.
 * Повторний запуск оновлює вже імпортовані записи за source_url — дублікатів
 * не буде.
 */
class ImportCatalog extends Command
{
    protected $signature = 'catalog:import
        {file : шлях до JSON відносно storage/app}
        {--publish : одразу публікувати (не радимо: імпорт без перевірки)}';

    protected $description = 'Завантажити спарсений каталог у базу як неопубліковані товари';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! Storage::exists($file)) {
            $this->error("storage/app/{$file} не знайдено. Спершу: php artisan scrape:budprokat");

            return self::FAILURE;
        }

        $data = json_decode(Storage::get($file), true);

        if (! is_array($data) || empty($data['products'])) {
            $this->error('Файл порожній або має неочікуваний формат.');

            return self::FAILURE;
        }

        // Бренд розпізнаємо з назви товару; невпізнане падає в «Інші».
        $brands = Brand::pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);

        $fallbackBrand = Brand::firstOrCreate(
            ['slug' => 'inshi'],
            ['name' => 'Інші', 'about' => 'Позиції з імпорту, бренд яких ще не розібраний.', 'why' => ''],
        );

        $created = $updated = 0;

        foreach ($data['products'] as $row) {
            $category = $this->category($row);
            $price = (int) $row['price'];

            $payload = [
                'category_id' => $category->id,
                'brand_id' => $this->brand($row['name'], $brands) ?? $fallbackBrand->id,
                'name' => $row['name'],
                'lead' => $row['description'] ?? null,
                'specs' => $row['specs'] ?: null,
                'base_price' => $price,
                // Застава: як на сторінці; якщо її там не було — типова для ніші
                // кратна ціні заглушка, яку менеджер виправить при перевірці.
                'deposit' => $row['deposit'] ?? (int) (ceil($price * 6 / 500) * 500),
                'published' => (bool) $this->option('publish'),
                'source_url' => $row['url'],
                'imported_at' => now(),
            ];

            $product = Product::withoutGlobalScopes()->where('source_url', $row['url'])->first();

            if ($product) {
                $product->update($payload);
                $updated++;
            } else {
                $product = Product::create($payload + [
                    'slug' => $this->uniqueSlug($row['name']),
                    'sku' => 'BUR-IMP-'.str_pad((string) (Product::withoutGlobalScopes()->count() + 1), 5, '0', STR_PAD_LEFT),
                ]);
                $created++;
            }

            // Сходинка за нашою моделлю: −17% від 3 днів, −31% від 7.
            $product->tiers()->delete();

            foreach ([
                ['1–2 дні', 1, 2, $price, 'базовий тариф'],
                ['3–6 днів', 3, 6, (int) (round($price * 0.83 / 10) * 10), '−17%'],
                ['від 7 днів', 7, null, (int) (round($price * 0.69 / 10) * 10), '−31%'],
            ] as [$label, $min, $max, $tierPrice, $note]) {
                $product->tiers()->create([
                    'label' => $label, 'min_days' => $min, 'max_days' => $max,
                    'price' => $tierPrice, 'note' => $note,
                ]);
            }
        }

        $this->info("Створено: {$created}, оновлено: {$updated}.");

        if (! $this->option('publish')) {
            $this->line('Усе лежить неопублікованим: адмінка → Товари → фільтр «Чернетки з імпорту».');
        }

        return self::SUCCESS;
    }

    private function category(array $row): Category
    {
        $name = $row['category'] ?? 'Імпорт без категорії';

        return Category::firstOrCreate(
            ['slug' => $this->slug($name)],
            ['name' => $name, 'source_url' => $row['url'] ?? null, 'products_count' => 0],
        );
    }

    private function brand(string $productName, $brands): ?int
    {
        foreach ($brands as $needle => $id) {
            if (str_contains(mb_strtolower($productName), $needle)) {
                return $id;
            }
        }

        return null;
    }

    private function uniqueSlug(string $name): string
    {
        $base = $this->slug($name);
        $slug = $base;
        $i = 2;

        while (Product::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function slug(string $value): string
    {
        return Str::slug(Str::ascii($value, 'uk')) ?: 'import-'.Str::random(6);
    }
}
