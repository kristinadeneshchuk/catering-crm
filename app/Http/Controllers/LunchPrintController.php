<?php

namespace App\Http\Controllers;

use App\Services\Kitchen\LunchAssembly;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Друк для корпоративних обідів: наліпки на бокси («хто що замовив») і
 * зборочний аркуш по компаніях. `day` — день доставки обіду.
 */
class LunchPrintController extends Controller
{
    private function day(Request $request): string
    {
        try {
            return Carbon::parse($request->input('day', now()->addDay()->toDateString()))->format('Y-m-d');
        } catch (\Throwable) {
            return now()->addDay()->format('Y-m-d');
        }
    }

    public function stickers(Request $request)
    {
        $day    = $this->day($request);
        $bag    = $request->input('bag', '1') !== '0';
        $format = $request->input('format') === 'large' ? 'large' : 'small';

        return view('print.lunch-stickers', [
            'day'      => $day,
            'bag'      => $bag,
            'format'   => $format,
            'stickers' => LunchAssembly::stickers($day, $bag),
            'perSheet' => $format === 'large' ? 9 : 21,
        ]);
    }

    public function assembly(Request $request)
    {
        $day = $this->day($request);

        return view('print.lunch-assembly', ['day' => $day, 'companies' => LunchAssembly::forDate($day)]);
    }
}
