<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Фото й файли чернеток (одометр, накладна, голосове) з приватного сховища.
 *
 * Лише для входу в CRM і за підписаним посиланням, лише з тек чернеток —
 * жодного довільного шляху з диска.
 */
class OpsAttachmentController extends Controller
{
    public const ALLOWED_PREFIXES = ['courier-reports/', 'ops/'];

    public function __invoke(Request $request)
    {
        $path = (string) $request->query('path', '');

        abort_if($path === '' || str_contains($path, '..'), 404);
        abort_unless(collect(self::ALLOWED_PREFIXES)->contains(fn ($p) => str_starts_with($path, $p)), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
