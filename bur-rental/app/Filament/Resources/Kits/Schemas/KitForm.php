<?php

namespace App\Filament\Resources\Kits\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class KitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('Назва')->required()->placeholder('Кладу плитку')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set, string $operation) => $operation === 'create' && $state
                        ? $set('slug', Str::slug($state))
                        : null),

                TextInput::make('slug')->label('Slug')->required()->unique(ignoreRecord: true),

                TextInput::make('task')->label('Формулювання задачі')
                    ->placeholder('Кладу плитку у ванній або на кухні')
                    ->helperText('Так задачу називає клієнт — саме це він шукає.'),

                TextInput::make('discount_percent')->label('Знижка комплекту, %')->numeric()->required()
                    ->helperText('Наскільки комплект дешевший за суму окремих позицій.'),

                TextInput::make('guide_url')->label('Посилання на повний гайд'),
                TextInput::make('position')->label('Порядок')->numeric(),
            ]),

            Section::make('Тексти')->schema([
                Textarea::make('lead')->label('Опис')->rows(3),
                Textarea::make('guide')->label('Коротка інструкція')->rows(6)
                    ->helperText('Кожен крок — з нового рядка. Нумерація проставляється сама.'),
            ]),

            Section::make('Склад комплекту')->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->relationship()
                    ->orderColumn('position')
                    ->columns(3)
                    ->schema([
                        Select::make('product_id')->label('Позиція')
                            ->relationship('product', 'name')
                            ->searchable()->preload()->required(),

                        TextInput::make('why')->label('Навіщо вона тут')
                            ->helperText('Один рядок пояснення — він показується під назвою.'),

                        Toggle::make('optional')->label('Можна прибрати')
                            ->helperText('Необов\'язкові позиції клієнт знімає, ціна перераховується.'),
                    ]),
            ]),
        ]);
    }
}
