<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\District;
use App\Models\Product;
use Illuminate\View\View;

class DistrictController extends Controller
{
    public function __invoke(City $city, District $district): View
    {
        $district->load('branches');

        // Найближча філія: своя по району, інакше найближча в місті.
        $nearest = $district->branches->first()
            ?? $city->branches()->orderBy('distance_km')->first();

        return view('pages.district', [
            'city' => $city,
            'district' => $district,
            'nearest' => $nearest,
            'popular' => Product::with(['brand', 'tiers'])
                ->orderByDesc('popularity')->take(8)->get(),
            'zones' => $city->deliveryZones,
        ]);
    }
}
