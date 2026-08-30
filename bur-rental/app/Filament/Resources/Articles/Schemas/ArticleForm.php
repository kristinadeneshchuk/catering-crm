<?php

namespace App\Filament\Resources\Articles\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title')->label('Заголовок')->required()->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set, $context) => $context === 'create'
                        ? $set('slug', Str::slug($state))
                        : null),

                TextInput::make('slug')->label('Адреса')->required()->unique(ignoreRecord: true)
                    ->helperText('Змінювати після публікації не варто: стара адреса віддасть 404.'),

                DatePicker::make('published_at')->label('Дата публікації')->default(now()),

                Textarea::make('excerpt')->label('Анонс')->rows(2)->maxLength(400)->required()
                    ->columnSpanFull()
                    ->helperText('Показується у списку статей і йде в опис сторінки для пошуку. До 400 символів.'),
            ]),

            Section::make('Текст')->schema([
                Textarea::make('body')->label('Markdown')->rows(24)->required()
                    ->helperText('## заголовок, **жирний**, - список, [текст](/посилання). HTML вирізається.'),
            ]),

            Section::make('Куди вести читача')->columns(2)->schema([
                // Стаття без переходу — просто текст. Комплект сильніший за
                // категорію: у ньому вже зібрано все під задачу.
                Select::make('kit_id')->label('Комплект під задачу')
                    ->relationship('kit', 'name')->searchable()->preload(),

                Select::make('category_id')->label('Категорія')
                    ->relationship('category', 'name')->searchable()->preload()
                    ->helperText('Показується, якщо комплект не вибраний.'),

                Toggle::make('published')->label('Опублікована')->default(true),
            ]),
        ]);
    }
}
