<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Builder;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationGroup = 'Система'; 
    protected static ?int $navigationSort = 3; // Буде після Користувачів
    protected static ?string $navigationLabel = 'Журнал транзакцій';
    protected static ?string $pluralModelLabel = 'Транзакції';
    protected static ?string $modelLabel = 'Транзакція';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Деталі транзакції')
                    ->columns(2)
                    ->schema([
                        Forms\Components\DatePicker::make('date')
                            ->label('Дата')
                            ->default(now())
                            ->required(),

                        Forms\Components\Select::make('type')
                            ->label('Тип')
                            ->options([
                                'income' => 'Дохід',
                                'expense' => 'Витрата',
                            ])
                            ->required(),

                        Forms\Components\Select::make('account_id')
                            ->label('Рахунок')
                            ->relationship('account', 'name')
                            ->required()
                            ->searchable(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Сума')
                            ->numeric()
                            ->prefix('₴')
                            ->required(),

                        Forms\Components\Select::make('employee_id')
                            ->label('Співробітник (для ЗП)')
                            ->relationship('employee', 'name')
                            ->searchable()
                            ->placeholder('Тільки для виплат ЗП'),

                        Forms\Components\Select::make('order_id')
                            ->label('Замовлення (для оплат)')
                            ->relationship('order', 'id')
                            ->searchable()
                            ->placeholder('Тільки для оплат клієнтів'),

                        Forms\Components\Textarea::make('comment')
                            ->label('Коментар')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('account.name')
                    ->label('Рахунок')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'income' => '📥 Дохід',
                        'expense' => '📤 Витрата',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'income' => 'success',
                        'expense' => 'danger',
                    }),

                TextColumn::make('amount')
                    ->label('Сума')
                    ->money('UAH')
                    ->weight('bold')
                    ->color(fn ($record) => $record->type === 'expense' ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Категорія')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Оплата клієнта'      => 'success',
                        'Повернення коштів'   => 'warning',
                        'Закупівля'           => 'warning',
                        'Списання зі складу'  => 'gray',
                        'Нове замовлення'     => 'info',
                        'Зміна замовлення'    => 'primary',
                        'Виплата ЗП'          => 'danger',
                        default               => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('details')
                    ->label('Деталі / Призначення')
                    ->getStateUsing(function ($record) {
                        if ($record->employee_id) {
                            return 'Виплата ЗП: ' . ($record->employee?->name ?? 'Видалений співробітник');
                        }
                        if ($record->stock_document_id) {
                            return '🏭 ' . ($record->comment ?: "Документ #{$record->stock_document_id}");
                        }
                        if ($record->order_id) {
                            return '🛒 Замовлення #' . $record->order_id;
                        }
                        return $record->comment ?: ($record->category ?? 'Інше');
                    })
                    ->searchable(['comment']),

                TextColumn::make('comment')
                    ->label('Коментар')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc') // Останні транзакції зверху
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'income'  => 'Дохід',
                        'expense' => 'Витрата',
                    ]),
                SelectFilter::make('category')
                    ->label('Категорія')
                    ->options([
                        'Оплата клієнта'     => 'Оплата клієнта',
                        'Повернення коштів'  => 'Повернення коштів',
                        'Нове замовлення'    => 'Нове замовлення',
                        'Зміна замовлення'   => 'Зміна замовлення',
                        'Виплата ЗП'         => 'Виплата ЗП',
                        'Закупівля'          => 'Закупівля',
                        'Списання зі складу' => 'Списання зі складу',
                    ]),
                SelectFilter::make('account_id')
                    ->label('Рахунок')
                    ->relationship('account', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),

                // 🔥 КНОПКА ВИДАЛЕННЯ (ВІДКОТ)
                DeleteAction::make()
                    ->label('Скасувати')
                    ->modalHeading('Видалити транзакцію?')
                    ->modalDescription('Увага: Гроші повернуться на рахунок, а борг співробітника (якщо це була ЗП) знову з’явиться в системі.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }
}