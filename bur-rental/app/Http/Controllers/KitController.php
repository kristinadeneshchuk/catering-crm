<?php

namespace App\Http\Controllers;

use App\Models\Extra;
use App\Models\Kit;
use Illuminate\View\View;

class KitController extends Controller
{
    public function index(): View
    {
        return view('pages.kits', [
            'kits' => Kit::with('items.product.tiers')->orderBy('position')->get(),
        ]);
    }

    public function show(Kit $kit): View
    {
        $kit->load(['items.product.brand', 'items.product.tiers']);

        return view('pages.kit', [
            'kit' => $kit,
            'others' => Kit::where('id', '!=', $kit->id)->with('items.product.tiers')->orderBy('position')->get(),
            'extras' => Extra::whereNull('category_id')->orWhereIn('slug', ['dysk-keramika-125', 'koronka-68'])->get(),
        ]);
    }
}
