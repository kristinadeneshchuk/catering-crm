<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([

                Tabs\Tab::make('Основне')->schema([
                    Section::make()->columns(2)->schema([
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(160)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, callable $set, string $operation) {
                                // Slug лізе в URL і в SEO, тому у вже опублікованого
                                // товару його не чіпаємо: зміна вб'є позиції й посилання.
                                if ($operation === 'create' && $state) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Частина адреси сторінки. У живого товару міняти не варто.'),

                        Select::make('category_id')
                            ->label('Категорія')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('brand_id')
                            ->label('Бренд')
                            ->relationship('brand', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('sku')
                            ->label('Артикул')
                            ->required()
                            ->unique(ignoreRecord: true),

                        TextInput::make('weight_kg')
                            ->label('Вага, кг')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Від 200 кг самовивіз недоступний — тільки доставка.'),

                        Toggle::make('published')
                            ->label('Опубліковано')
                            ->helperText('Вимкнено — позиція є в адмінці, але не на сайті. Імпорт приходить вимкненим.'),

                        TextInput::make('source_url')
                            ->label('Джерело імпорту')
                            ->disabled()
                            ->visible(fn ($record) => filled($record?->source_url)),
                    ]),

                    Section::make('Тексти')->schema([
                        Textarea::make('lead')->label('Короткий опис')->rows(3)
                            ->helperText('Перший абзац у вкладці «Опис» і в description сторінки.'),
                        Textarea::make('description')->label('Повний опис')->rows(4),
                        Textarea::make('seo_text')->label('SEO-текст')->rows(6)
                            ->helperText('Показується під контентом. Порожній рядок = новий абзац.'),
                    ]),
                ]),

                Tabs\Tab::make('Тариф і застава')->schema([
                    Section::make()->columns(3)->schema([
                        TextInput::make('base_price')
                            ->label('Базовий тариф, ₴/день')
                            ->numeric()->required()
                            ->helperText('Ціна за 1–2 дні. Від неї рахується економія на сходинці.'),

                        TextInput::make('deposit')
                            ->label('Застава, ₴')
                            ->numeric()->required()
                            ->helperText('Повертається клієнту при поверненні справного інструменту.'),

                        TextInput::make('retail_price')
                            ->label('Ціна купівлі, ₴')
                            ->numeric()
                            ->helperText('Для блоку «Оренда чи купівля». Не показується як ціна товару.'),
                    ]),

                    Section::make('Тарифна сходинка')
                        ->description('Три рівні: чим довший строк, тим дешевший день. Рівні не мають перекриватися.')
                        ->schema([
                            Repeater::make('tiers')
                                ->hiddenLabel()
                                ->relationship()
                                ->columns(5)
                                ->defaultItems(3)
                                ->reorderable(false)
                                ->schema([
                                    TextInput::make('label')->label('Підпис')->required()
                                        ->placeholder('3–6 днів'),
                                    TextInput::make('min_days')->label('Від, днів')->numeric()->required(),
                                    TextInput::make('max_days')->label('До, днів')->numeric()
                                        ->helperText('Порожньо = без межі'),
                                    TextInput::make('price')->label('₴/день')->numeric()->required(),
                                    TextInput::make('note')->label('Бейдж')->placeholder('−17%'),
                                ]),
                        ]),
                ]),

                Tabs\Tab::make('Характеристики')->schema([
                    KeyValue::make('specs')
                        ->label('Таблиця характеристик')
                        ->keyLabel('Параметр')->valueLabel('Значення')
                        ->helperText('Перші п\'ять рядків підсвічуються на сторінці як ключові.'),

                    TagsInput::make('key_specs')
                        ->label('Ключові — у картці лістингу')
                        ->helperText('2–3 значення, наприклад «2,7 Дж», «800 Вт», «2,7 кг».'),

                    TagsInput::make('kit')->label('Що входить у комплект'),
                    TagsInput::make('not_included')->label('Що НЕ входить')
                        ->helperText('Витратники, які клієнт докуповує на видачі.'),
                ]),

                Tabs\Tab::make('Зв\'язки і файли')->schema([
                    Section::make()->columns(2)->schema([
                        Select::make('extras')
                            ->label('Витратники до цієї моделі')
                            ->relationship('extras', 'name')
                            ->multiple()->searchable()->preload(),

                        Select::make('related')
                            ->label('З цим орендують')
                            ->relationship('related', 'name')
                            ->multiple()->searchable()->preload()
                            ->helperText('Сам товар у цей блок не потрапляє.'),

                        Select::make('similar')
                            ->label('Схожі моделі (порівняння)')
                            ->relationship('similar', 'name')
                            ->multiple()->searchable()->preload()
                            ->helperText('Показуються в таблиці порівняння по трьох параметрах.'),
                    ]),

                    Section::make()->columns(2)->schema([
                        TextInput::make('manual_url')->label('Інструкція, PDF')->url(),
                        TextInput::make('video_url')->label('Відео')->url(),
                        TextInput::make('popularity')->label('Популярність')->numeric()
                            ->helperText('Чим більше, тим вище в сортуванні «за релевантністю».'),
                    ]),
                ]),
            ]),
        ]);
    }
}
