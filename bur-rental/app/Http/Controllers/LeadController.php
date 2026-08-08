<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;

class LeadController extends Controller
{
    public function store(StoreLeadRequest $request): RedirectResponse
    {
        Lead::create($request->validated());

        return back()->with('lead', match ($request->string('kind')->toString()) {
            'b2b' => 'Запит прийнято. Персональний менеджер надішле комерційну пропозицію протягом робочого дня.',
            'notify' => 'Повідомимо, щойно модель звільниться.',
            default => 'Заявку прийнято. Менеджер набере вас протягом 15 хвилин.',
        });
    }
}
