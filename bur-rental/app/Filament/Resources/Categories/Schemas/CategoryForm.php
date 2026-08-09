<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('Назва')->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set, string $operation) => $operation === 'create' && $state
                        ? $set('slug', Str::slug($state))
                        : null),

                TextInput::make('slug')->label('Slug')->required()->unique(ignoreRecord: true),

                TextInput::make('name_genitive')
                    ->label('Назва в родовому відмінку')
                    ->placeholder('перфораторів і відбійників')
                    ->helperText('Підставляється в заголовок «Оренда … у Києві».'),

                Select::make('parent_id')
                    ->label('Батьківська категорія')
                    ->relationship('parent', 'name')
                    ->searchable()->preload()
                    ->helperText('Порожньо = категорія першого рівня.'),

                TextInput::make('products_count')->label('Позицій (для меню)')->numeric()
                    ->helperText('Число поруч із назвою в каталозі.'),

                TextInput::make('position')->label('Порядок')->numeric(),

                Toggle::make('heavy')
                    ->label('Важка техніка')
                    ->helperText('Такі позиції возимо самі; самовивіз обмежений.'),
            ]),

            Section::make('Тексти')->schema([
                Textarea::make('lead')->label('Опис під заголовком')->rows(3),
                TagsInput::make('filter_specs')
                    ->label('Ключові параметри фільтра')
                    ->helperText('Технічні характеристики, за якими фільтрують саме цю категорію.'),
                Textarea::make('seo_text')->label('SEO-текст')->rows(6),
            ]),
        ]);
    }
}
