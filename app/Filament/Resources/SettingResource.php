<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SettingResource\Pages;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder; // Додав імпорт для Query Builder

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;
    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Налаштування бізнесу';
    
    // === ВИПРАВЛЕННЯ 1: Забираємо зайву "s" ===
    protected static ?string $modelLabel = 'Налаштування';       // Однина
    protected static ?string $pluralModelLabel = 'Налаштування'; // Множина (тепер однакові)

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Глобальні параметри')
                    ->schema([
                        Forms\Components\TextInput::make('key')
                            ->label('Параметр (Ключ)')
                            ->disabled() 
                            ->dehydrated(), 

                        Forms\Components\TextInput::make('value')
                            ->label('Значення')
                            ->required()
                            ->helperText('Для параметра "menu_cycle_days" вкажіть кількість днів циклу (напр. 24 або 30).'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // === ВИПРАВЛЕННЯ 2: Приховуємо технічні рядки ===
            ->modifyQueryUsing(function (Builder $query) {
                // Показуємо тільки ті рядки, які НЕ починаються на "stock_debited"
                return $query->where('key', 'not like', 'stock_debited_%');
            })
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Назва налаштування')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'menu_cycle_days' => 'Тривалість циклу меню (днів)',
                        default => $state, // Інші ключі показуємо як є
                    }),
                Tables\Columns\TextColumn::make('value')
                    ->label('Поточне значення')
                    ->badge()
                    ->color('success'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            // Забороняємо створювати та видаляти налаштування, щоб нічого не зламати
            // (Ви можете це розкоментувати, якщо хочете додати кнопку "Створити")
            // ->bulkActions([]) 
            ; 
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSettings::route('/'),
            'edit' => Pages\EditSetting::route('/{record}/edit'),
        ];
    }
}