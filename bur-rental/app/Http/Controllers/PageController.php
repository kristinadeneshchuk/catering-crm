<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function terms(): View
    {
        return view('pages.terms', ['faqs' => Faq::scope('rental')->get()]);
    }

    public function delivery(Request $request): View
    {
        $city = $request->attributes->get('city');

        return view('pages.delivery', [
            'zones' => $city->deliveryZones,
            'faqs' => Faq::scope('delivery')->get(),
        ]);
    }

    public function returns(): View
    {
        return view('pages.returns', ['faqs' => Faq::scope('return')->get()]);
    }

    public function contacts(Request $request): View
    {
        $city = $request->attributes->get('city');

        return view('pages.contacts', ['city' => $city->load('branches')]);
    }

    public function b2b(): View
    {
        return view('pages.b2b', ['faqs' => Faq::scope('b2b')->get()]);
    }
}
