<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BrandController extends Controller
{
    public function __invoke(Request $request, Brand $brand): View
    {
        $products = $brand->products()
            ->with(['tiers', 'brand', 'category'])
            ->orderByDesc('popularity')
            ->paginate(24)
            ->withQueryString();

        return view('pages.brand', [
            'brand' => $brand,
            'products' => $products,
            'categories' => $brand->products()->with('category')->get()
                ->pluck('category')->unique('id')->values(),
        ]);
    }
}
