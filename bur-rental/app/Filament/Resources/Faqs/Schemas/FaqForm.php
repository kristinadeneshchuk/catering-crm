<?php

namespace App\Filament\Resources\Faqs\Schemas;

use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('scope')
                    ->label('Розділ сайту')
                    ->options(self::scopes())
                    ->placeholder('Не прив\'язано до розділу')
                    ->helperText('FAQ інформаційних сторінок. Для товару чи категорії лишіть порожнім і оберіть об\'єкт нижче.'),

                TextInput::make('position')->label('Порядок')->numeric(),

                MorphToSelect::make('faqable')
                    ->label('Або конкретний об\'єкт')
                    ->types([
                        MorphToSelect\Type::make(Product::class)->titleAttribute('name')->label('Товар'),
                        MorphToSelect\Type::make(Category::class)->titleAttribute('name')->label('Категорія'),
                        MorphToSelect\Type::make(City::class)->titleAttribute('name')->label('Місто'),
                    ])
                    ->searchable()
                    ->columnSpanFull(),
            ]),

            TextInput::make('question')->label('Питання')->required()->columnSpanFull(),
            Textarea::make('answer')->label('Відповідь')->rows(3)->required()
                ->helperText('Відповідь потрапляє в розмітку FAQPage — пишіть конкретно, без води.'),
        ]);
    }

    /** @return array<string, string> */
    public static function scopes(): array
    {
        return [
            'rental' => 'Умови оренди',
            'delivery' => 'Доставка й оплата',
            'return' => 'Повернення',
            'b2b' => 'Для юросіб',
        ];
    }
}
