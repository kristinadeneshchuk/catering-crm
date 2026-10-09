<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\AccountResource\Pages;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class AccountResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = Account::class;

    protected static ?string $navigationLabel = 'Рахунки';
    protected static ?string $modelLabel = 'Рахунок';
    protected static ?string $pluralModelLabel = 'Рахунки';
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 7;
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Card::make()->schema([
                    TextInput::make('name')
                        ->label('Назва рахунку')
                        ->required()
                        ->placeholder('Напр. Розрахунковий рахунок')
                        ->columnSpanFull(),

                    Select::make('type')
                        ->label('Тип рахунку')
                        ->options([
                            'cash' => 'Готівка (Каса)',
                            'online' => 'Онлайн оплата',
                            'card' => 'Картка',
                        ])
                        ->required(),

                    TextInput::make('balance')
                        ->label('Поточний баланс')
                        ->numeric()
                        ->prefix('₴')
                        ->default(0)
                        ->required(),

                    Toggle::make('is_default')
                        ->label('Рахунок за замовчуванням для оплат')
                        ->onColor('success')
                        ->offColor('gray')
                        ->columnSpanFull(),
                ])->columns(2),

                // Банк — лише супер адмін. Збережений токен у браузер не віддаємо:
                // поле завжди порожнє, порожнє при збереженні = «не міняти».
                Forms\Components\Section::make('monobank')
                    ->description('Автозвірка оплат з банком. Токен: api.monobank.ua → «Отримати токен».')
                    ->visible(fn () => auth()->user()?->isSuperAdmin())
                    ->schema([
                        TextInput::make('mono_token')
                            ->label('Токен monobank')
                            ->password()
                            ->autocomplete('new-password')
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                            ->dehydrated(fn ($state) => filled($state))
                            ->placeholder(fn (?Account $record) => $record?->maskedMonoToken() ?? 'не задано')
                            ->helperText(fn (?Account $record) => $record?->hasMonoToken()
                                ? 'Токен збережено ' . $record->maskedMonoToken() . '. Щоб замінити — вставте новий.'
                                : 'Зберігається зашифрованим, у логи не потрапляє.')
                            ->columnSpanFull(),
                        TextInput::make('mono_account_id')
                            ->label('id рахунку в monobank')
                            ->helperText('Порожньо — візьмемо гривневий рахунок ФОП автоматично.'),
                        Forms\Components\Placeholder::make('mono_status')
                            ->label('Стан')
                            ->content(fn (?Account $record) => $record?->mono_synced_at
                                ? 'IBAN ' . ($record->mono_iban ?? '—') . ' · підтягнуто ' . $record->mono_synced_at->format('d.m H:i')
                                : 'ще не підтягувалось'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('№')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Назва рахунку')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'cash' => 'warning',   // Жовтий для готівки
                        'online' => 'success', // Зелений для онлайн
                        'card' => 'info',      // Синій для картки
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'Готівка',
                        'online' => 'Онлайн',
                        'card' => 'Картка',
                        default => $state,
                    }),

                TextColumn::make('balance')
                    ->label('Баланс')
                    ->money('UAH') // Автоматично додасть знак ₴ і пробіли
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label('За замовч.')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->alignCenter(),
            ])
            ->defaultSort('id', 'asc')
            ->actions([
                Tables\Actions\Action::make('mono_sync')
                    ->label('')
                    ->tooltip('Підтягнути виписку monobank за 31 день')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Account $record) => auth()->user()?->isSuperAdmin() && $record->hasMonoToken())
                    ->requiresConfirmation()
                    ->modalHeading('Підтягнути виписку з monobank?')
                    ->modalDescription('Займе 1–3 хвилини: банк дозволяє один запит на хвилину. Операції зʼявляться в «Банк».')
                    ->action(function (Account $record) {
                        \App\Jobs\SyncMonobankAccount::dispatch($record->id);
                        \Filament\Notifications\Notification::make()->title('Запит у банк поставлено в чергу')->success()->send();
                    }),
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}