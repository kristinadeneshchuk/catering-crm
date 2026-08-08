<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\City;
use App\Models\Faq;
use App\Models\Product;
use Illuminate\View\View;

class CityController extends Controller
{
    public function __invoke(City $city): View
    {
        $city->load(['branches.district', 'districts', 'deliveryZones', 'reviews']);

        $branchIds = $city->branches->pluck('id');

        return view('pages.city', [
            'city' => $city,
            'categories' => Category::roots()->take(8)->get(),
            'popular' => Product::with(['brand', 'tiers'])
                ->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds))
                ->orderByDesc('popularity')->take(8)->get(),
            'faqs' => Faq::where('faqable_type', City::class)
                ->where('faqable_id', $city->id)->orderBy('position')->get(),
        ]);
    }
}
