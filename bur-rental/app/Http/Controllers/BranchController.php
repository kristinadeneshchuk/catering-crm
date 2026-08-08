<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\City;
use App\Models\Product;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function __invoke(City $city, Branch $branch): View
    {
        $branch->load(['city.districts', 'district', 'reviews']);

        // Живий залишок саме цієї філії — те, заради чого сторінку й відкривають.
        $stock = Product::with(['brand', 'tiers', 'unavailableDates'])
            ->whereHas('branches', fn ($q) => $q->whereKey($branch->id))
            ->orderByDesc('popularity')
            ->take(12)
            ->get();

        return view('pages.branch', [
            'city' => $city,
            'branch' => $branch,
            'stock' => $stock,
            'others' => $city->branches->where('id', '!=', $branch->id),
        ]);
    }
}
