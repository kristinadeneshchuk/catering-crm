<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Видача плюс одна ознака: чи це точні збіги, чи вже виправлення одруківки.
 *
 * Різниця важлива для сторінки: показати «перфоратор» у відповідь на
 * «перфаратор» — добре, а от мовчки вдати, що це саме те, що просили, — ні.
 */
readonly class SearchResult
{
    /** @param  Collection<int, Product>  $products */
    public function __construct(
        public Collection $products,
        public bool $corrected = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->products->isEmpty();
    }
}
