<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function show(Request $request, Product $product): View
    {
        $city = $request->attributes->get('city');

        $product->load([
            'brand', 'category.parent', 'tiers', 'extras', 'faqs',
            'reviews', 'unavailableDates',
            'related.brand', 'related.tiers', 'related.unavailableDates',
            'similar.brand', 'similar.tiers',
        ]);

        // Філії саме обраного міста: показувати склад в іншому місті — обман.
        $branches = $city->branches->whereIn('id', $product->branches->pluck('id'))->values();

        return view('pages.product', [
            'product' => $product,
            'branches' => $branches,
            'busy' => $product->busyByBranch(),
        ]);
    }
}
