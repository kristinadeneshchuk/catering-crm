<?php

namespace App\Filament\Resources\LunchOrderResource\Widgets;

use App\Filament\Resources\ClientResource;
use App\Models\LunchCompanyLink;
use App\Support\SchemaReady;
use Filament\Widgets\Widget;

/** Розрахунки з компаніями за обіди: нараховано, оплачено, борг. */
class LunchSettlements extends Widget
{
    protected static string $view = 'filament.widgets.lunch-settlements';
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isAdmin() && SchemaReady::has('lunch_company_links');
    }

    protected function getViewData(): array
    {
        $rows = LunchCompanyLink::with('client')->orderBy('company_name')->get()
            ->filter(fn (LunchCompanyLink $l) => $l->client)
            ->groupBy('client_id')
            ->map(function ($links) {
                $client  = $links->first()->client;
                $charged = $client->lunchCharged();
                $paid    = $client->lunchPaid();

                return [
                    'company' => $links->pluck('company_name')->filter()->implode(', '),
                    'client'  => $client->name,
                    'url'     => ClientResource::getUrl('edit', ['record' => $client->id]),
                    'charged' => $charged,
                    'paid'    => $paid,
                    'debt'    => $charged - $paid,
                ];
            })->values()->all();

        return ['rows' => $rows];
    }
}
