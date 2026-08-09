<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;
use App\Services\ManagerAlerts;
use Illuminate\Http\RedirectResponse;

class LeadController extends Controller
{
    public function store(StoreLeadRequest $request): RedirectResponse
    {
        $lead = Lead::create($request->validated());

        app(ManagerAlerts::class)->leadCreated($lead);

        return back()->with('lead', match ($request->string('kind')->toString()) {
            'b2b' => 'Запит прийнято. Персональний менеджер надішле комерційну пропозицію протягом робочого дня.',
            'notify' => 'Повідомимо, щойно модель звільниться.',
            default => 'Заявку прийнято. Менеджер набере вас протягом 15 хвилин.',
        });
    }
}
