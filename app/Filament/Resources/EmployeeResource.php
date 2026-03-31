<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Models\Employee;
use App\Models\Account;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationLabel = 'Співробітники';
    protected static ?string $pluralModelLabel = 'Співробітники';
    protected static ?string $modelLabel = 'Співробітник';
    protected static ?string $navigationGroup = 'Система'; 

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Основна інформація')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('ПІБ')
                            ->required()
                            ->placeholder('Іванов Іван'),

                        Select::make('position')
                            ->label('Посада')
                            ->options([
                                'cook' => 'Кухар',
                                'courier' => 'Кур\'єр',
                                'manager' => 'Менеджер',
                                'packer' => 'Пакувальник',
                                'admin' => 'Адміністратор',
                            ])
                            ->required()
                            ->searchable(),

                        TextInput::make('base_rate')
                            ->label('Ставка за зміну')
                            ->numeric()
                            ->prefix('₴')
                            ->default(0)
                            ->required(),

                        TextInput::make('balance')
                            ->label('Поточний баланс (Борг перед ним)')
                            ->numeric()
                            ->prefix('₴')
                            ->default(0)
                            ->disabled() 
                            ->dehydrated(), 

                        Toggle::make('is_active')
                            ->label('Працює')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('ПІБ')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('position')
                    ->label('Посада')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cook' => 'Кухар',
                        'courier' => 'Кур\'єр',
                        'manager' => 'Менеджер',
                        'packer' => 'Пакувальник',
                        'admin' => 'Адмін',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'cook' => 'warning',
                        'courier' => 'info',
                        'manager' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('base_rate')
                    ->label('Ставка')
                    ->money('UAH')
                    ->sortable(),

                TextColumn::make('balance')
                    ->label('Борг компанії')
                    ->money('UAH')
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Тільки активні'),
                Tables\Filters\SelectFilter::make('position')->label('Посада')->options([
                    'cook' => 'Кухар',
                    'courier' => 'Кур\'єр',
                    'manager' => 'Менеджер',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),

                // 🔥 ФІНАЛЬНИЙ БЛОК: ВИПЛАТА ЗП
                Action::make('pay_salary')
                    ->label('Виплатити ЗП')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    // Показуємо кнопку лише якщо ми щось винні людині
                    ->visible(fn (Employee $record) => $record->balance > 0)
                    ->modalHeading(fn (Employee $record) => "Виплата для: {$record->name}")
                    ->modalDescription('Оберіть рахунок для списання та підтвердіть суму.')
                    ->modalSubmitActionLabel('Підтвердити виплату')
                    
                    ->form([
                        Select::make('account_id')
                            ->label('Рахунок списання')
                            ->options(fn () => Account::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->placeholder('Виберіть касу або картку'),

                        TextInput::make('amount')
                            ->label('Сума до виплати')
                            ->numeric()
                            ->required()
                            ->default(fn (Employee $record) => $record->balance)
                            ->suffix('₴')
                            ->hint(fn (Employee $record) => "Борг: " . number_format($record->balance, 0) . " ₴"),
                    ])
                        ->action(function (Employee $record, array $data): void {
                        DB::transaction(function () use ($record, $data) {
                            $amount = (float) $data['amount'];
                            $account = Account::findOrFail($data['account_id']);

                            // 1. Зменшуємо борг співробітника (це робимо вручну, бо це не транзакція клієнта)
                            $record->decrement('balance', $amount);

                            // ❌ РЯДОК $account->decrement(...) ВИДАЛЕНО, щоб не було подвійного списання

                            // 2. Створюємо фінансову транзакцію. 
                            // Автоматика вашої системи (Observer) сама побачить тип 'expense' 
                            // і відніме суму від балансу рахунку $account->id.
                            Transaction::create([
                                'employee_id' => $record->id,
                                'order_id'    => null,
                                'account_id'  => $account->id,
                                'amount'      => $amount,
                                'type'        => 'expense',
                                'category'    => 'Виплата ЗП',
                                'date'        => now(),
                                'comment'     => "Виплата ЗП: {$record->name}",
                                'user_id'     => auth()->id(),
                            ]);
                        });

                        Notification::make()
                            ->title('Виплату проведено')
                            ->body("Борг співробітника зменшено, транзакцію зафіксовано.")
                            ->success()
                            ->send();
                    }),
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
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}