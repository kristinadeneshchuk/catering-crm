<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Пошук по каталогу без окремого пошукового движка.
 *
 * Meilisearch тут був би правильним інструментом, але сайт живе на шаред-хостингу
 * без SSH — демона нема куди поставити. Тому весь розум винесено в підготовку:
 * `products.search_text` уже містить назву, артикул, бренд, категорію і їхню
 * транслітерацію, а запит перед порівнянням розкладається на варіанти написання.
 * На каталозі в кілька тисяч позицій цього вистачає з запасом; якщо колись
 * з'явиться свій сервер — цей клас і буде тим місцем, куди підключиться движок.
 */
class ProductSearch
{
    /** Скільки рядків максимум перебирати вручну, коли точний пошук порожній. */
    private const FUZZY_SCAN_LIMIT = 5000;

    public function __construct(private readonly SearchTerms $terms) {}

    /**
     * Рядок, за яким товар шукається. Складається при кожному збереженні.
     *
     * Опис навмисно не входить: у ньому «надійний», «зручний» і «професійний»,
     * через які будь-який запит знаходить пів каталогу.
     */
    public function haystack(Product $product): string
    {
        $parts = [
            $product->name,
            $product->sku,
            $product->brand?->name,
            $product->category?->name,
        ];

        $normalized = array_filter(array_map(
            fn (?string $part) => $part ? $this->terms->normalize($part) : null,
            $parts
        ));

        // Транслітерація в той самий рядок: так «perforator» знаходить
        // «перфоратор», а «бош» — «bosch», без окремої колонки й окремої гілки.
        $translit = array_filter(array_map(
            fn (string $part) => $this->terms->translit($part),
            $normalized
        ));

        return implode(' ', array_unique([...$normalized, ...$translit]));
    }

    /** Точний пошук: кожне слово запиту має знайтися хоч в одному написанні. */
    public function apply(Builder $query, ?string $term): Builder
    {
        $tokens = $this->terms->tokens((string) $term);

        if (! $tokens) {
            return $query;
        }

        foreach ($tokens as $token) {
            $query->where(function (Builder $q) use ($token) {
                foreach ($this->terms->variants($token) as $variant) {
                    $q->orWhere('search_text', 'like', '%'.$variant.'%');
                }
            });
        }

        return $query->orderByDesc('popularity');
    }

    /** Пошук з підстраховкою на одруківки. */
    public function find(?string $term, int $limit = 24): SearchResult
    {
        $tokens = $this->terms->tokens((string) $term);

        if (! $tokens) {
            return new SearchResult(collect());
        }

        // Беремо ширше за сторінку і переставляємо вже в PHP: збіг у назві має
        // випереджати збіг у категорії, а зробити це в SQL однаково на MySQL і
        // SQLite не виходить — SQLite'ів LOWER() не чіпає кирилицю.
        $found = $this->apply(Product::with(['brand', 'tiers']), $term)
            ->take(min($limit * 4, 100))
            ->get();

        if ($found->isNotEmpty()) {
            return new SearchResult($this->rank($found, $tokens)->take($limit)->values());
        }

        $fuzzy = $this->fuzzy($tokens, $limit);

        return new SearchResult($fuzzy, corrected: $fuzzy->isNotEmpty());
    }

    /**
     * Сортування видачі: спершу те, де слово запиту стоїть у самій назві.
     *
     * На запит «бош» клієнт чекає інструменти Bosch, а не всю категорію
     * перфораторів, куди бренд теж потрапив через search_text.
     *
     * @param  Collection<int, Product>  $products
     * @param  list<string>  $tokens
     * @return Collection<int, Product>
     */
    private function rank(Collection $products, array $tokens): Collection
    {
        return $products->sortBy([
            fn (Product $p) => $this->hitsInName($p, $tokens) ? 0 : 1,
            fn (Product $p) => -$p->popularity,
        ]);
    }

    /** @param  list<string>  $tokens */
    private function hitsInName(Product $product, array $tokens): bool
    {
        $name = $this->terms->normalize($product->name.' '.$product->brand?->name);

        foreach ($tokens as $token) {
            foreach ($this->terms->variants($token) as $variant) {
                if (str_contains($name, $variant)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Останній шанс: перебрати каталог і пробачити одруківку.
     *
     * Це свідомо повний перебір, але тільки двох колонок і тільки тоді, коли
     * точний пошук уже нічого не дав — тобто на запиті, який інакше віддав би
     * порожню сторінку. Дешевше показати «перфоратор» на «перфаратор», ніж
     * втратити клієнта.
     *
     * @param  list<string>  $tokens
     * @return Collection<int, Product>
     */
    private function fuzzy(array $tokens, int $limit): Collection
    {
        $rows = Product::query()
            ->orderByDesc('popularity')
            ->take(self::FUZZY_SCAN_LIMIT)
            ->pluck('search_text', 'id');

        $ids = $rows
            ->filter(fn (?string $haystack) => $haystack && $this->allTokensLookLike($tokens, $haystack))
            ->keys()
            ->take($limit);

        if ($ids->isEmpty()) {
            return collect();
        }

        return Product::with(['brand', 'tiers'])
            ->whereIn('id', $ids)
            ->orderByDesc('popularity')
            ->get();
    }

    /** @param  list<string>  $tokens */
    private function allTokensLookLike(array $tokens, string $haystack): bool
    {
        $words = preg_split('~\s+~u', $haystack, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $matched = false;

            foreach ($words as $word) {
                if ($this->terms->looksLike($token, $word)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }
}
