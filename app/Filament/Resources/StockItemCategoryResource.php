<?php
namespace App\Filament\Resources;

use App\Filament\Resources\StockItemCategoryResource\Pages;
use App\Models\StockItemCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StockItemCategoryResource extends Resource
{
    protected static ?string $model = StockItemCategory::class;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $pluralModelLabel = 'Категорії товарів';
    protected static ?string $modelLabel = 'Категорія';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Назва категорії')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(2),

                Forms\Components\Select::make('model_class')
                    ->label('До якого довідника відносяться товари')
                    ->options([
                        'App\Models\Ingredient' => 'Інгредієнти (продукти, м\'ясо, овочі...)',
                        'App\Models\Packaging'  => 'Упаковка (пакети, контейнери, госптовари...)',
                    ])
                    ->required()
                    ->helperText('Від цього залежить який список товарів буде доступний при виборі')
                    ->columnSpan(2),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок сортування')
                    ->numeric()
                    ->default(0)
                    ->columnSpan(1),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Назва'),
                Tables\Columns\TextColumn::make('model_class')
                    ->label('Тип товарів')
                    ->formatStateUsing(fn ($state) => match($state) {
                        'App\Models\Ingredient' => 'Інгредієнти',
                        'App\Models\Packaging'  => 'Упаковка',
                        default => $state,
                    })
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('sort_order')->label('Порядок')->alignCenter()->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListStockItemCategories::route('/'),
            'create' => Pages\CreateStockItemCategory::route('/create'),
            'edit'   => Pages\EditStockItemCategory::route('/{record}/edit'),
        ];
    }
}
