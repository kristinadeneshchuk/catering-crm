<?php

namespace App\Filament\Resources;


use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
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

    public static function canAccess(): bool
    {
        return auth()->user()->role === 'admin';
    }

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

                        TextInput::make('ant_driver_name')
                            ->label('Ім\'я в ANT Logistics')
                            ->placeholder('Точно як у АНТ, напр. "Іванов І.І."')
                            ->helperText('Заповни для кур\'єрів — система автоматично зв\'яже маршрути з цим співробітником')
                            ->nullable(),

                        Select::make('position')
                            ->label('Посада')
                            ->options(fn () => \App\Models\Position::where('is_active', true)->orderBy('sort_order')->pluck('name', 'key'))
                            ->required()
                            ->searchable()
                            ->live(),

                        TextInput::make('base_rate')
                            ->label(fn (\Filament\Forms\Get $get) =>
                                optional(\App\Models\Position::where('key', $get('position'))->first())->payment_type === 'per_month'
                                    ? 'Оклад за місяць'
                                    : 'Ставка за зміну')
                            ->numeric()
                            ->prefix('₴')
                            ->default(0)
                            ->required(),

                        Select::make('project_id')
                            ->label('Бренд (для аналітики)')
                            ->options(fn () => \App\Models\Project::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->helperText('Для посад із розділенням витрат по брендах — ЗП піде в аналітику цього бренду.'),

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
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn ($state, Employee $record): string => $record->positionData?->name ?? $state)
                    ->color(fn ($state, Employee $record): string => $record->positionData?->color ?? 'gray'),

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

                // Борг (нараховано ЗП) за обраний у фільтрі діапазон дат. '—' якщо діапазон не задано.
                TextColumn::make('accrued_period')
                    ->label('Нараховано за період')
                    ->money('UAH')
                    ->weight('bold')
                    ->color('warning')
                    ->placeholder('—')
                    ->state(function (Employee $record, $livewire) {
                        $f    = $livewire->tableFilters['accrued'] ?? [];
                        $from = $f['from'] ?? null;
                        $to   = $f['to'] ?? null;
                        if (! $from && ! $to) {
                            return null; // діапазон не задано
                        }
                        $q = $record->shifts();
                        if ($from) $q->whereDate('date', '>=', \Illuminate\Support\Carbon::parse($from)->toDateString());
                        if ($to)   $q->whereDate('date', '<=', \Illuminate\Support\Carbon::parse($to)->toDateString());
                        return (float) $q->sum('rate');
                    }),

                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),

                TextColumn::make('archived_at')
                    ->label('В архіві з')
                    ->dateTime('d.m.Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('archived')
                    ->label('Архів')
                    ->placeholder('Активні')
                    ->trueLabel('В архіві')
                    ->falseLabel('Усі (з архівом)')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('archived_at'),
                        false: fn ($query) => $query,
                        blank: fn ($query) => $query->whereNull('archived_at'),
                    ),
                Tables\Filters\TernaryFilter::make('is_active')->label('Тільки активні'),
                Tables\Filters\SelectFilter::make('position')->label('Посада')
                    ->options(fn () => \App\Models\Position::orderBy('sort_order')->pluck('name', 'key')),

                // Нараховано ЗП за період: показує суму ставок змін у діапазоні дат
                Tables\Filters\Filter::make('accrued')
                    ->label('Нараховано за період')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Нараховано з')->native(false),
                        \Filament\Forms\Components\DatePicker::make('to')->label('по')->native(false),
                    ])
                    // Рядки не фільтруємо — дати читає колонка «Нараховано за період»
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query) => $query)
                    ->indicateUsing(function (array $data) {
                        if (! empty($data['from']) || ! empty($data['to'])) {
                            return 'Нараховано: ' . ($data['from'] ?? '…') . ' – ' . ($data['to'] ?? '…');
                        }
                        return null;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),

                // Архівувати (приховує зі списків + знімає is_active, історія лишається)
                Action::make('archive')
                    ->label('')
                    ->tooltip('Архівувати')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Employee $record) => "Архівувати: {$record->name}?")
                    ->modalDescription('Співробітник зникне зі списків і всіх робочих графіків. Історія (зміни, штрафи, виплати) збережеться. Відновити можна будь-коли.')
                    ->modalSubmitActionLabel('Архівувати')
                    ->visible(fn (Employee $record) => is_null($record->archived_at))
                    ->action(function (Employee $record): void {
                        $record->update(['archived_at' => now(), 'is_active' => false]);
                        Notification::make()->title('Співробітника заархівовано')->success()->send();
                    }),

                // Відновити з архіву
                Action::make('restore')
                    ->label('')
                    ->tooltip('Відновити з архіву')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Employee $record) => "Відновити: {$record->name}?")
                    ->modalSubmitActionLabel('Відновити')
                    ->visible(fn (Employee $record) => ! is_null($record->archived_at))
                    ->action(function (Employee $record): void {
                        $record->update(['archived_at' => null, 'is_active' => true]);
                        Notification::make()->title('Співробітника відновлено')->success()->send();
                    }),

                // 🔥 ІСТОРІЯ + ВИПЛАТА ЗП
                Action::make('pay_salary')
                    ->label('Історія / Виплата')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading(fn (Employee $record) => $record->name)
                    ->modalDescription('Хронологія всіх нарахувань і списань. Якщо є борг — внизу можна виплатити.')
                    ->modalContent(fn (Employee $record) => view('filament.components.employee-history', ['employee' => $record]))
                    ->modalSubmitActionLabel('Виплатити')
                    ->modalCancelActionLabel('Закрити')
                    ->modalSubmitAction(fn ($action, Employee $record) => $record->balance > 0 ? $action : false)
                    ->form(fn (Employee $record) => $record->balance > 0 ? [
                        Select::make('account_id')
                            ->label('Рахунок списання')
                            ->options(fn () => Account::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->default(fn () => Account::where('is_default', true)->value('id') ?? Account::orderBy('id')->value('id'))
                            ->placeholder('Виберіть касу або картку'),

                        TextInput::make('amount')
                            ->label('Сума до виплати')
                            ->numeric()
                            ->required()
                            ->default(fn () => $record->balance)
                            ->suffix('₴')
                            ->hint("Борг: " . number_format($record->balance, 0) . " ₴"),

                        TextInput::make('comment')
                            ->label('Коментар (необов\'язково)')
                            ->placeholder('напр. зарплата за червень'),
                    ] : [])
                    ->action(function (Employee $record, array $data): void {
                        if ($record->balance <= 0) {
                            Notification::make()->title('Немає боргу для виплати')->warning()->send();
                            return;
                        }

                        DB::transaction(function () use ($record, $data) {
                            $amount  = (float) $data['amount'];
                            $account = Account::findOrFail($data['account_id']);
                            $comment = $data['comment'] ?? null;

                            $record->decrement('balance', $amount);

                            Transaction::create([
                                'employee_id' => $record->id,
                                'order_id'    => null,
                                'account_id'  => $account->id,
                                'amount'      => $amount,
                                'type'        => 'expense',
                                'category'    => 'Виплата ЗП',
                                'date'        => now(),
                                'comment'     => $comment ?: "Виплата ЗП: {$record->name}",
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
                    Tables\Actions\BulkAction::make('archive')
                        ->label('Архівувати')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Архівувати обраних співробітників?')
                        ->modalDescription('Вони зникнуть зі списків і робочих графіків. Історія збережеться, відновити можна будь-коли.')
                        ->action(fn (\Illuminate\Database\Eloquent\Collection $records) => $records->each->update(['archived_at' => now(), 'is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PenaltiesRelationManager::class,
        ];
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