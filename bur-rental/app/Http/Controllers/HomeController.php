<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\City;
use App\Models\Kit;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $city = $request->attributes->get('city');

        return view('pages.home', [
            'categories' => Category::roots()->take(10)->get(),
            'kits' => Kit::orderBy('position')->get(),
            'popular' => Product::with(['brand', 'tiers'])
                ->orderByDesc('popularity')->take(8)->get(),
            'branches' => $city->branches,
            'cities' => City::orderBy('position')->get(),
            // Свіжі відгуки з Google — соціальний доказ прямо на головній.
            'reviews' => Review::google()->latest('published_at')->take(3)->get(),
            // Статті ловлять тих, хто ще не знає, що саме йому орендувати.
            // На головній вони мають бути видні: інакше блог живе сам по собі
            // і не отримує ні читачів, ні внутрішньої ваги.
            'articles' => Article::with('kit')->orderBy('position')->take(3)->get(),
        ]);
    }
}
