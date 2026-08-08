<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Kit;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $term = trim($request->string('q')->toString());

        return view('pages.search', [
            'term' => $term,
            'products' => $term
                ? Product::with(['brand', 'tiers'])->search($term)->take(24)->get()
                : collect(),
            'categories' => $term
                ? Category::where('name', 'like', "%{$term}%")->take(6)->get()
                : collect(),
            'kits' => $term
                ? Kit::where('name', 'like', "%{$term}%")->orWhere('task', 'like', "%{$term}%")->take(4)->get()
                : collect(),
            'popular' => Product::with(['brand', 'tiers'])->orderByDesc('popularity')->take(4)->get(),
        ]);
    }
}
