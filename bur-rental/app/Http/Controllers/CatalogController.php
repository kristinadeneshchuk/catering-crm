<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(): View
    {
        // Без withCount: він створює атрибут products_count і затирає колонку,
        // через що категорія з товарами лише в підкатегоріях показувала «0 позицій».
        return view('pages.catalog', [
            'categories' => Category::roots()->with('children')->get(),
        ]);
    }

    public function show(Request $request, Category $category): View
    {
        $city = $request->attributes->get('city');

        $branch = $request->filled('branch')
            ? $city->branches->firstWhere('slug', $request->string('branch')->toString())
            : null;

        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        // Категорія показує і власні товари, і товари підкатегорій — інакше
        // «Перфоратори» виглядали б порожніми, бо все лежить у SDS-plus.
        $categoryIds = [$category->id, ...$category->children->pluck('id')];

        $query = Product::query()
            ->with(['brand', 'tiers', 'branches'])
            ->whereIn('category_id', $categoryIds)
            ->inBranch($branch)
            ->when($request->filled('brand'), fn ($q) => $q->whereHas(
                'brand',
                fn ($b) => $b->whereIn('slug', (array) $request->input('brand'))
            ))
            ->when($request->boolean('free'), fn ($q) => $q->freeBetween($from, $to, $branch));

        $sorted = match ($request->string('sort')->toString()) {
            'price-asc' => $query->orderBy('base_price'),
            'price-desc' => $query->orderByDesc('base_price'),
            'weight' => $query->orderBy('weight_kg'),
            'popular' => $query->orderByDesc('popularity'),
            default => $query->orderByDesc('popularity'),
        };

        return view('pages.category', [
            'category' => $category,
            'products' => $sorted->paginate(24)->withQueryString(),
            'brands' => Brand::whereHas('products', fn ($q) => $q->whereIn('category_id', $categoryIds))
                ->withCount(['products' => fn ($q) => $q->whereIn('category_id', $categoryIds)])
                ->get(),
            'branches' => $city->branches,
            'activeBranch' => $branch,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
