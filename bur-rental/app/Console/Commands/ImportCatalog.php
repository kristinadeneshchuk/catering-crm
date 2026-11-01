<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Faq;
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

        // Нові гілки дерева йдуть разом із товарами: без батьківської
        // категорії позиція не має куди лягти.
        foreach ($data['categories'] ?? [] as $i => $c) {
            $category = Category::firstOrCreate(['slug' => $c['slug']], [
                'parent_id' => empty($c['parent']) ? null : Category::where('slug', $c['parent'])->firstOrFail()->id,
                'name' => $c['name'], 'name_genitive' => $c['name_genitive'] ?? null,
                'lead' => $c['lead'] ?? null, 'products_count' => 0, 'position' => 100 + $i,
            ]);

            // Тексти й FAQ оновлюються при кожному запуску: правка в JSON
            // має доїжджати на сайт без ручного втручання.
            $category->update(array_filter(['seo_text' => $c['seo_text'] ?? null, 'lead' => $c['lead'] ?? null]));

            foreach ($c['faqs'] ?? [] as $n => [$question, $answer]) {
                Faq::updateOrCreate(
                    ['faqable_type' => Category::class, 'faqable_id' => $category->id, 'position' => $n],
                    ['question' => $question, 'answer' => $answer],
                );
            }
        }

        $created = $updated = 0;

        foreach ($data['products'] as $row) {
            $category = $this->category($row);
            $price = (int) $row['price'];

            $payload = [
                'category_id' => $category->id,
                'brand_id' => $this->brand($row['name'], $brands, $row['brand'] ?? null) ?? $fallbackBrand->id,
                'name' => $row['name'],
                'lead' => $row['description'] ?? null,
                'description' => $row['description_full'] ?? null,
                'specs' => ($row['specs'] ?? null) ?: null,
                'key_specs' => $row['key_specs'] ?? null,
                'weight_kg' => $row['weight'] ?? null,
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
                    'sku' => 'TP-IMP-'.str_pad((string) (Product::withoutGlobalScopes()->count() + 1), 5, '0', STR_PAD_LEFT),
                ]);
                $created++;
            }

            $this->stock($product);

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

        // Лічильники в меню й картках рахуються так само, як у сидах.
        Category::query()->with('children')->get()->each(function (Category $category) {
            $own = Product::withoutGlobalScopes()->where('published', true)->where('category_id', $category->id)->count();
            $inChildren = Product::withoutGlobalScopes()->where('published', true)->whereIn('category_id', $category->children->pluck('id'))->count();
            $category->update(['products_count' => $own + $inChildren]);
        });

        $this->info("Створено: {$created}, оновлено: {$updated}.");

        if (! $this->option('publish')) {
            $this->line('Усе лежить неопублікованим: адмінка → Товари → фільтр «Чернетки з імпорту».');
        }

        return self::SUCCESS;
    }

    /**
     * Без наявності позиція на вітрині «немає на ваші дати». Імпорт кладе
     * по одиниці в кожну філію; важке (від 60 кг) — лише в Київ, як і сиди.
     * Те, що вже є на складі, не чіпаємо: кількість править менеджер.
     */
    private function stock(Product $product): void
    {
        if ($product->branches()->exists()) {
            return;
        }

        foreach (Branch::with('city')->get() as $branch) {
            if ($branch->city->slug !== 'kyiv' && $product->weight_kg >= 60) {
                continue;
            }

            $product->branches()->attach($branch->id, ['qty' => 1]);
        }
    }

    private function category(array $row): Category
    {
        // Коли категорію вже розкладено по нашому дереву — беремо її як є.
        if (! empty($row['category_slug'])) {
            return Category::where('slug', $row['category_slug'])->firstOrFail();
        }

        $name = $row['category'] ?? 'Імпорт без категорії';

        return Category::firstOrCreate(
            ['slug' => $this->slug($name)],
            ['name' => $name, 'source_url' => $row['url'] ?? null, 'products_count' => 0],
        );
    }

    private function brand(string $productName, $brands, ?string $explicit = null): ?int
    {
        // Виробник зі сторінки надійніший за здогадку з назви: нового
        // заводимо одразу, бо сторінка бренду без нього — 404.
        if ($explicit) {
            $brand = Brand::firstOrCreate(['slug' => $this->slug($explicit)], ['name' => $explicit]);
            $brands[mb_strtolower($explicit)] = $brand->id;

            return $brand->id;
        }

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
