<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Kit;
use App\Models\Product;
use App\Services\Search\ProductSearch;
use App\Services\Search\SearchTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __construct(
        private readonly ProductSearch $search,
        private readonly SearchTerms $terms,
    ) {}

    public function __invoke(Request $request): View
    {
        $term = trim($request->string('q')->toString());
        $result = $this->search->find($term);

        return view('pages.search', [
            'term' => $term,
            'products' => $result->products,
            'corrected' => $result->corrected,
            'categories' => $this->matching(Category::query(), ['name'], $term, 6),
            'kits' => $this->matching(Kit::query(), ['name', 'task'], $term, 4),
            'popular' => Product::with(['brand', 'tiers'])->orderByDesc('popularity')->take(4)->get(),
        ]);
    }

    /**
     * Категорії й комплекти шукаються по тих самих варіантах написання, що й
     * товари — інакше «rfntujhbz» знаходило б перфоратор, але не категорію.
     *
     * @param  list<string>  $columns
     * @return Collection<int, mixed>
     */
    private function matching(Builder $query, array $columns, string $term, int $limit): Collection
    {
        $tokens = $this->terms->tokens($term);

        if (! $tokens) {
            return collect();
        }

        foreach ($tokens as $token) {
            $query->where(function (Builder $q) use ($columns, $token) {
                foreach ($this->terms->variants($token) as $variant) {
                    // Назви в базі з великої літери, а запит зведено до малих.
                    // MySQL із ci-порівнянням це пробачає, SQLite — тільки для
                    // латиниці, тож кириличний варіант із великої додаємо самі.
                    foreach ([$variant, Str::ucfirst($variant)] as $spelling) {
                        foreach ($columns as $column) {
                            $q->orWhere($column, 'like', '%'.$spelling.'%');
                        }
                    }
                }
            });
        }

        return $query->take($limit)->get();
    }
}
