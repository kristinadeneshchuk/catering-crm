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
        return view('pages.catalog', [
            'categories' => Category::roots()->withCount('products')->get(),
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

        $query = Product::query()
            ->with(['brand', 'tiers', 'branches'])
            ->whereIn('category_id', [$category->id, ...$category->children->pluck('id')])
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
            'brands' => Brand::whereHas(
                'products',
                fn ($q) => $q->where('category_id', $category->id)
            )->withCount(['products' => fn ($q) => $q->where('category_id', $category->id)])->get(),
            'branches' => $city->branches,
            'activeBranch' => $branch,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
