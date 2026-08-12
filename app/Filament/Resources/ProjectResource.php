<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProjectResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = Project::class;

    protected static ?string $navigationGroup = 'Система'; // Назва групи має збігатися з існуючою
    protected static ?string $navigationLabel = 'Проєкти (Бренди)';
    protected static ?int $navigationSort = 1; // Буде першим у списку системи

    protected static ?string $modelLabel = 'Проєкт';
    protected static ?string $pluralModelLabel = 'Проєкти';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Інформація про бізнес')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Назва проєкту')
                            ->required()
                            ->live(onBlur: true)
                            // Автоматично генеруємо slug з назви при створенні
                            ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) => 
                                $operation === 'create' ? $set('slug', Str::slug($state, '_')) : null
                            ),
                            
                        Forms\Components\TextInput::make('slug')
                            ->label('Системна назва (slug)')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Важливо для старих даних! Наприклад: avocado_food, u_fit'),
                            
                        Forms\Components\Select::make('color')
                            ->label('Колір бейджа в системі')
                            ->options([
                                'primary' => 'Синій (Primary)',
                                'success' => 'Зелений (Success)',
                                'warning' => 'Жовтий (Warning)',
                                'danger' => 'Червоний (Danger)',
                                'info' => 'Блакитний (Info)',
                                'gray' => 'Сірий (Gray)',
                            ])
                            ->default('success')
                            ->required(),
                            
                        Forms\Components\Toggle::make('is_active')
                            ->label('Активний')
                            ->default(true)
                            ->inline(false),

                        Forms\Components\FileUpload::make('logo')
                            ->label('Логотип')
                            ->image()
                            ->directory('projects-logos')
                            ->columnSpanFull(),
                    ]),

                // У кожного бренду свій ФОП і свій рахунок. У виставлений
                // рахунок реквізити копіюються знімком, тому зміна тут не
                // перепише документи, які клієнти вже отримали.
                Forms\Components\Section::make('Реквізити для рахунків')
                    ->description('Підставляються у рахунок на оплату. Без отримувача та IBAN рахунок виставити не вийде.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('recipient_name')
                            ->label('Отримувач')
                            ->placeholder('ФОП Прізвище Ім\'я По батькові')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('iban')
                            ->label('IBAN')
                            ->placeholder('UA00 0000 0000 0000 0000 0000 000')
                            ->maxLength(64),

                        Forms\Components\TextInput::make('tax_id')
                            ->label('ЄДРПОУ / ІПН')
                            ->maxLength(32),

                        Forms\Components\TextInput::make('bank_name')
                            ->label('Банк')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('mfo')
                            ->label('МФО')
                            ->maxLength(16),

                        Forms\Components\TextInput::make('payment_purpose')
                            ->label('Шаблон призначення платежу')
                            ->helperText('Доступні підстановки: :number — номер рахунку, :date — дата, :brand — бренд. Порожньо — стандартний текст.')
                            ->placeholder('оплата згідно рахунку №:number від :date за доставку здорового харчування')
                            ->columnSpanFull()
                            ->maxLength(500),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Логотип')
                    ->circular()
                    ->defaultImageUrl(url('https://ui-avatars.com/api/?name=P&color=7F9CF5&background=EBF4FF')),

                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Системна назва')
                    ->badge()
                    // Колір бейджа береться з того, що ти вибрала при створенні!
                    ->color(fn (Project $record): string => $record->color),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Активний'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
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
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}