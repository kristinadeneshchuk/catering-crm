<?php

namespace App\Filament\Resources\Extras;

use App\Filament\Resources\Extras\Pages\CreateExtra;
use App\Filament\Resources\Extras\Pages\EditExtra;
use App\Filament\Resources\Extras\Pages\ListExtras;
use App\Filament\Resources\Extras\Schemas\ExtraForm;
use App\Filament\Resources\Extras\Tables\ExtrasTable;
use App\Models\Extra;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ExtraResource extends Resource
{
    protected static ?string $model = Extra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?string $navigationLabel = 'Витратники';

    protected static ?string $modelLabel = 'витратник';

    protected static ?string $pluralModelLabel = 'витратники';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ExtraForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExtrasTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExtras::route('/'),
            'create' => CreateExtra::route('/create'),
            'edit' => EditExtra::route('/{record}/edit'),
        ];
    }
}
