<?php

namespace App\Filament\Resources\Reviews\Schemas;

use App\Models\Branch;
use App\Models\City;
use App\Models\Product;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                // Один відгук може висіти на товарі, філії або місті —
                // блок відгуків на всіх трьох сторінках один і той самий.
                MorphToSelect::make('reviewable')
                    ->label('До чого відгук')
                    ->types([
                        MorphToSelect\Type::make(Product::class)->titleAttribute('name')->label('Товар'),
                        MorphToSelect\Type::make(Branch::class)->titleAttribute('name')->label('Філія'),
                        MorphToSelect\Type::make(City::class)->titleAttribute('name')->label('Місто'),
                    ])
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),

                TextInput::make('author')->label('Автор')->required(),
                TextInput::make('author_note')->label('Підпис автора')->placeholder('юрособа, ФОП'),

                Select::make('rating')->label('Оцінка')
                    ->options([5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★'])
                    ->required(),

                Select::make('source')->label('Джерело')
                    ->options(['site' => 'Сайт', 'google' => 'Google'])
                    ->required(),

                DatePicker::make('published_at')->label('Дата')->required()->default(today()),
            ]),

            Textarea::make('body')->label('Текст')->rows(4)->required(),
        ]);
    }
}
