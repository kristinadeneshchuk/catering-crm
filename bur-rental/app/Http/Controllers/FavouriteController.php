<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Обране.
 *
 * Працює без входу: серце тисне і незалогінений — список живе в localStorage
 * поруч із кошиком. Вимагати реєстрацію заради закладки означало б втратити
 * і закладку, і клієнта. Після входу гостьовий список зливається з серверним.
 */
class FavouriteController extends Controller
{
    public function index(): View
    {
        $client = Auth::guard('client')->user();

        return view('pages.favourites', [
            'client' => $client,
            'products' => $client ? $client->favourites()->with(['brand', 'tiers'])->get() : collect(),
        ]);
    }

    /**
     * Картки обраного для гостя.
     *
     * Список гостя лежить у localStorage, тому сторінка не може відрендерити
     * його на сервері — вона доклацує сюди зі списком id. Тягнути на сторінку
     * весь каталог і ховати зайве було б простіше, але це трафік, який росте
     * разом із каталогом і нікому не потрібен.
     */
    public function items(Request $request): View
    {
        $ids = collect(explode(',', $request->string('ids')->toString()))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->take(60);

        return view('components.favourite-list', [
            'products' => $ids->isEmpty()
                ? collect()
                : Product::with(['brand', 'tiers'])->whereIn('id', $ids)->get(),
        ]);
    }

    /** Перемикач серця. Гостю відповідає без запису — стан тримає браузер. */
    public function toggle(Request $request, Product $product): JsonResponse
    {
        $client = Auth::guard('client')->user();

        if (! $client) {
            return response()->json(['saved' => false, 'guest' => true]);
        }

        $saved = $client->favourites()->toggle($product->id);

        return response()->json(['saved' => (bool) $saved['attached']]);
    }

    /**
     * Зливає гостьовий список у серверний після входу.
     *
     * Саме злиття, а не заміна: людина могла додавати обране і з телефона, і
     * з ноутбука, і жоден із двох списків не має права зникнути.
     */
    public function sync(Request $request): JsonResponse
    {
        $client = Auth::guard('client')->user();

        if (! $client instanceof Client) {
            return response()->json(['saved' => []], 401);
        }

        $ids = collect($request->input('ids', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id);

        if ($ids->isNotEmpty()) {
            $client->favourites()->syncWithoutDetaching(
                Product::whereIn('id', $ids)->pluck('id')
            );
        }

        return response()->json(['saved' => $client->favourites()->pluck('products.id')]);
    }
}
