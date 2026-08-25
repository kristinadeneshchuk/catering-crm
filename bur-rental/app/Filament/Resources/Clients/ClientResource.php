<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Клієнти з кабінету.
 *
 * Питання, заради якого цей розділ існує: хто до нас повертається. У бронях
 * видно окремі замовлення, але не видно, що ці шість — від однієї бригади,
 * яка вже принесла 40 тисяч і має право на іншу розмову про ціну.
 *
 * Створення руками немає: клієнт з'являється сам при першому вході в кабінет,
 * бо ідентифікує його телефон і одноразовий код. Заводити «порожнього» клієнта
 * з адмінки — це рядок, до якого ніхто ніколи не увійде.
 */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Замовлення';

    protected static ?string $navigationLabel = 'Клієнти';

    protected static ?string $modelLabel = 'клієнт';

    protected static ?string $pluralModelLabel = 'клієнти';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'phone';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
