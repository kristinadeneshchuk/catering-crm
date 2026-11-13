<?php

namespace App\Filament\Resources\LunchOrderResource\Pages;

use App\Filament\Resources\LunchOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListLunchOrders extends ListRecords
{
    protected static string $resource = LunchOrderResource::class;

    /** Друк для кухні: день доставки обіду → наліпки на бокси або зборочний аркуш. */
    protected function getHeaderActions(): array
    {
        $print = fn (string $name, string $label, string $icon, string $route) => \Filament\Actions\Action::make($name)
            ->label($label)->icon($icon)->color('gray')
            ->form([
                \Filament\Forms\Components\DatePicker::make('day')->label('День доставки обідів')
                    ->default(now()->addDay()->toDateString())->required(),
            ])
            ->modalSubmitActionLabel('Відкрити для друку')
            ->action(fn (array $data, $livewire) => $livewire->js(
                'window.open('.json_encode(route($route, ['day' => $data['day']])).', "_blank")'
            ));

        return [
            $print('stickers', 'Наліпки на бокси', 'heroicon-o-tag', 'print.lunch-stickers'),
            $print('assembly', 'Зборочний аркуш', 'heroicon-o-clipboard-document-check', 'print.lunch-assembly'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [LunchOrderResource\Widgets\LunchSettlements::class];
    }
}
