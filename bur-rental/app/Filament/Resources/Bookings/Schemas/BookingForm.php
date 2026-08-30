<?php

namespace App\Filament\Resources\Bookings\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Бронь')->columns(3)->schema([
                TextInput::make('number')->label('Номер')->disabled()
                    ->helperText('Присвоюється автоматично.'),

                Select::make('status')
                    ->label('Статус')
                    ->options(self::statuses())
                    ->required()
                    ->helperText('Статус краще міняти кнопками у списку — вони ще й правлять календар.'),

                Select::make('branch_id')
                    ->label('Філія видачі')
                    ->relationship('branch', 'name')
                    ->searchable()->preload()->required(),

                DatePicker::make('date_from')->label('З')->required(),
                DatePicker::make('date_to')->label('По')->required(),
            ]),

            Section::make('Клієнт')->columns(3)->schema([
                Select::make('client_type')
                    ->label('Тип')
                    ->options(['person' => 'Фізособа', 'company' => 'Юрособа / ФОП'])
                    ->live()->required(),

                TextInput::make('phone')->label('Телефон')->tel()->required(),
                TextInput::make('name')->label('Ім\'я')
                    ->visible(fn (callable $get) => $get('client_type') === 'person'),

                TextInput::make('company')->label('Компанія')
                    ->visible(fn (callable $get) => $get('client_type') === 'company'),
                TextInput::make('edrpou')->label('ЄДРПОУ')
                    ->visible(fn (callable $get) => $get('client_type') === 'company'),
                TextInput::make('email')->label('Email для рахунку')->email(),
            ]),

            Section::make('Видача і оплата')->columns(3)->schema([
                Select::make('fulfilment')
                    ->label('Спосіб')
                    ->options(['self' => 'Самовивіз', 'delivery' => 'Доставка'])
                    ->live()->required(),

                Select::make('delivery_zone_id')
                    ->label('Зона доставки')
                    ->relationship('deliveryZone', 'name')
                    ->visible(fn (callable $get) => $get('fulfilment') === 'delivery'),

                TextInput::make('address')->label('Адреса')
                    ->visible(fn (callable $get) => $get('fulfilment') === 'delivery'),

                Select::make('payment')->label('Оплата')->options([
                    'card' => 'Картка онлайн',
                    'cash' => 'Готівка на видачі',
                    'invoice' => 'Рахунок для юросіб',
                    'parts' => 'Оплата частинами',
                ])->required(),

                Select::make('deposit_way')->label('Застава')->options([
                    'card-hold' => 'Заморозка на картці',
                    'cash' => 'Готівкою',
                    'none' => 'За договором',
                ])->required(),
            ]),

            Section::make('Суми')->columns(4)->schema([
                TextInput::make('rent_total')->label('Оренда, ₴')->numeric()
                    ->helperText('Перераховується автоматично при прийманні техніки.'),
                TextInput::make('extras_total')->label('Витратники, ₴')->numeric(),
                TextInput::make('delivery_total')->label('Доставка, ₴')->numeric(),
                TextInput::make('deposit_total')->label('Застава, ₴')->numeric(),

                // Відсоток зафіксований на момент бронювання: клієнт його
                // бачив, і заднім числом він не міняється. Сума перераховується
                // від нього при прийманні техніки.
                TextInput::make('discount_percent')->label('Знижка, %')->numeric()
                    ->minValue(0)->maxValue(config('loyalty.max_percent', 10))
                    ->helperText('Постійного клієнта. Діє тільки на оренду.'),
                TextInput::make('discount_total')->label('Знижка, ₴')->numeric()
                    ->helperText('Перераховується при прийманні.'),
            ]),

            Section::make()->schema([
                Textarea::make('comment')->label('Коментар')->rows(3),
            ]),
        ]);
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'new' => 'Нова',
            'confirmed' => 'Підтверджена',
            'issued' => 'Видана',
            'closed' => 'Закрита',
            'cancelled' => 'Скасована',
        ];
    }
}
